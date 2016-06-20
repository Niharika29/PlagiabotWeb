$( document ).ready( function () {
	/** Listeners */
	$( 'body' ).tooltip( {
		selector: '[data-toggle="tooltip"]'
	} );
	$( '.records' ).on( 'click', '.js-save-state', function () {
		var status = $( this ).data( 'status' ),
			id = $( this ).data( 'id' );

		// undo review if they click on the button with the same status as the record
		if ( status === $( '.record-' + id ).data( 'status' ) ) {
			undoReview( id, status );
		} else {
			saveState( id, status );
		}
	} );
	$( '.records' ).on( 'click', '.js-compare-button', function () {
		// pass the dataset of the element as an object to toggleComparePane
		toggleComparePane.call(this, this.dataset);
	} );
	$( '.js-load-more' ).on( 'click', loadMoreResults );

	/**
	 * Save a review
	 * @param id int ID of the record
	 * @param val string Save value 'fixed' or 'false'
	 */
	function saveState( id, val ) {
		// update styles before AJAX to make it seem more responsive
		setReviewState( id, val );

		$.ajax( {
			url: 'review/add',
			data: {
				id: id,
				val: val
			},
			dataType: 'json'
		} ).done( function ( ret ) {
			if ( ret.user ) {
				$reviewerNode = $( '.status-div-reviewer-' + id );
				$reviewerNode.find( '.reviewer-link' ).prop( 'href', ret.userpage ).text( ret.user );
				$reviewerNode.find( '.reviewer-timestamp' ).text( ret.timestamp );
				$reviewerNode.fadeIn( 'slow' );
			} else if ( ret.error === 'Unauthorized' ) {
				alert( 'You need to be logged in to be able to review.' );
				// go back to initial state
				setReviewState( id, 'open' );
			} else {
				alert( 'There was an error in connecting to database.' );
				setReviewState( id, 'open' );
			}

			document.activeElement.blur(); // remove focus from button
		} );
	}

	/**
	 * Undo a review
	 * @param id int ID of the record
	 * @param oldStatus string current review state of the record
	 */
	function undoReview( id, oldStatus ) {
		// update styles before AJAX to make it seem more responsive
		setReviewState( id, 'open' );

		$.ajax( {
			url: 'review/add',
			data: {
				id: id,
				undo: true
			},
			dataType: 'json'
		} ).done( function ( ret ) {
			if ( ret.user ) {
				$reviewerNode = $( '.status-div-reviewer-' + id );
				$reviewerNode.fadeOut( 'slow' );
			} else {
				alert( 'There was an error in connecting to database.' );
				setReviewState( id, oldStatus ); // revert back to old state
			}

			document.activeElement.blur(); // remove focus from button
		} );
	}

	/**
	 * Set the CSS class of the record in view, which updates the appearance of the review buttons
	 * @param id int ID of the record
	 * @param state string record state, must be 'open', 'fixed' or 'false'
	 */
	function setReviewState( id, state ) {
		$( '.record-' + id )
			.removeClass( 'record-status-open' )
			.removeClass( 'record-status-false' )
			.removeClass( 'record-status-fixed' )
			.addClass( 'record-status-' + state )
			.data( 'status', state );
	}

	/**
	 * Load more results to the page when 'Load More' is clicked
	 */
	function loadMoreResults() {
		$( '#btn-load-more' ).text( '' ).addClass( 'btn-loading' );
		var lastId = $( '.ithenticate-id:last' ).text();
		$.ajax( {
			url: 'loadmore',
			data: {
				lastId: lastId,
				filter: $( 'input[name=filter]:checked' ).val()
			}
		} ).done( function ( ret ) {
			$( '#btn-load-more' ).text( 'Load More' ).removeClass( 'btn-loading' );
			$newRecords = $( ret ).find( '.record-container' );
			$( '.record-container' ).append( $newRecords.html() );
		} );
	}

	/**
	 * Open the compare pane and do an AJAX request to Copyvios to fetch comparison data
	 * @oaram object params a hash of the necessary params, should include:
	 *   integer id Ithenticate ID of record
	 *   integer index Index of the link in the copyvios list for the record
	 *   string copyvio Copyvio URL
	 *   integer diffid Oldid of diff
	 */
	function toggleComparePane(params) {
		var compareDiv = '#comp' + params.id + '-' + params.index;
		$( compareDiv ).slideToggle( 500 );
		if ( !$( compareDiv ).hasClass( 'copyvios-fetched' ) ) {
			$.ajax( {
				type: 'GET',
				url: 'https://tools.wmflabs.org/copyvios/api.json',
				data: {
					oldid: params.diffid,
					url: params.copyvio,
					action: 'compare',
					project: 'wikipedia',
					lang: 'en',
					format: 'json',
					detail: 'true'
				},
				dataType: 'json',
				jsonpCallback: 'callback'
			} ).done( function ( ret ) {
				console.log( 'XHR Success' );
				if ( ret.detail ) {
					// Add a class to the compare panel once we fetch the details to avoid making repetitive API requests
					$( compareDiv ).find( '.compare-pane-left' ).html( ret.detail.article );
					$( compareDiv ).find( '.compare-pane-right' ).html( ret.detail.source );
				} else {
					$( compareDiv ).find( '.compare-pane-left' ).html( '<span class="text-danger">Error! API returned no data.</span>' );
					$( compareDiv ).find( '.compare-pane-right' ).html( '<span class="text-danger">Error! API returned no data.</span>' );
				}
				$( compareDiv ).addClass( 'copyvios-fetched' );
			} );
		}
	}
} );
