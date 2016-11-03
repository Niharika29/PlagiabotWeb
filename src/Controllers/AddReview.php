<?php
/**
 * This file is part of CopyPatrol application
 * Copyright (C) 2016  Niharika Kohli and contributors
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Niharika Kohli <nkohli@wikimedia.org>
 * @copyright © 2016 Niharika Kohli and contributors.
 */
namespace Plagiabot\Web\Controllers;

class AddReview extends CopyPatrol {

	protected function handleGet() {
		$id = $this->request->get( 'id' );
		$userData = $this->authManager->getUserData();
		$user = $userData ? $userData->getName() : null;
		// Get current UTC time as ISO 8601 timestamp.
		$timestamp = gmdate( 'c' );
		$val = $this->request->get( 'val' );

		$ret = $this->dao->insertCopyvioAssessment( $id, $val, $user, $timestamp );

		// Return JSON with username and review timestamp if review was successful
		if ( $ret === true ) {
			echo json_encode(
				[
					'user' => $user,
					'userpage' => $this->getUserPage( $user ),
					'timestamp' => $this->formatTimestamp( $timestamp ),
					'status' => $val
				] );
		} else {
			echo json_encode(
				[
					'error' => 'false'
				] );
		}
	}
}
