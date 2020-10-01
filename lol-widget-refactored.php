<?php
/**
* Plugin Name: LOL Matches
* Description: This plugin is to fetch LOL data from PandaScore API.
* Version: 1.0
* Author: Hackerman
**/

// add_shortcode('lol_data', 'test_lol_data');

// This function is to get data from API and store it in db, don't edit this
function test_lol_data() {
	global $wpdb;

	function get_data($url) {
		$curl = curl_init($url);
		
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

		curl_setopt($curl, CURLOPT_HTTPHEADER, [
	  		'Authorization: Bearer 3-tfXLZ4_eUwaD3Kd8-SkgUpakZVR-VEXNU1j-H3Z-cTHDSYO00',
	  		'Content-Type: application/json'
		]);

		if(curl_error($curl)) {
			echo "curl error";
		} else {
			$headers = [];
			curl_setopt($curl, CURLOPT_HEADERFUNCTION,
			  function($curl, $header) use (&$headers)
			  {
			    $len = strlen($header);
			    $header = explode(':', $header, 2);
			    if (count($header) < 2) // ignore invalid headers
			      return $len;

			    $headers[strtolower(trim($header[0]))][] = trim($header[1]);

			    return $len;
			  }
			);

			$data = curl_exec($curl);
			curl_close($curl);

			$data = json_decode($data);
			
			return array($data, $headers['x-total'][0]);
		} // end else
	} // end get_data

	function insert_into_db() {
		global $wpdb;

		$url = 'https://api.pandascore.co/lol/';

		$dataPerPage = 100;
		
		$requestUrl = $url . '/matches/upcoming?page[size]=' . $dataPerPage;

		$data = get_data($requestUrl)[0];

		$totalMatches = get_data($requestUrl)[1];

		if($data == NULL) {
			echo 'No data received!<br>Please check the filters and parameters';
			return;
		} elseif($data->error) {
			echo $data->error;
			return;
		}

		for ( $i = 0; $i < count($data); $i++ ) {

			$dateTime = $data[$i]->begin_at;

			preg_match('/(.*)T/', $dateTime, $matchDate);
			
			preg_match('/T(.*)Z/', $dateTime, $matchTime);

			$matchDate = $matchDate[1];

			$matchTime = $matchTime[1];

			$wpdb->insert( 'lol_data_'.date("d_m_y"), array(
				'id' => $data[$i]->id,
				'league' => $data[$i]->league->name,
				'league_id' => $data[$i]->league->id,
				'league_img' => $data[$i]->league->image_url,
				'match_date' => $matchDate,
				'match_time_UTC' => $matchTime,
				'team_1' => $data[$i]->opponents[0]->opponent->name,
				'team_1_img' => $data[$i]->opponents[0]->opponent->image_url,
				'team_2' => $data[$i]->opponents[1]->opponent->name,
				'team_2_img' => $data[$i]->opponents[1]->opponent->image_url
				)
			);
		} // end for
		echo 'Total matches: ' . $totalMatches;
		return;
	} // end insert_into_db

	$charset_collate = $wpdb -> get_charset_collate();

	$tableName = 'lol_data_' . date("d_m_y");

	if ( empty($wpdb -> get_results("SELECT id FROM $tableName")) ) {

		$create = "CREATE TABLE $tableName ( 
			id int,
			league varchar(255) NOT NULL,
			league_id int NOT NULL,
			league_img varchar(255) NOT NULL,
			match_date date NOT NULL,
			match_time_UTC time NOT NULL,
			team_1 varchar(255),
			team_1_img varchar(255),
			team_2 varchar(255),
			team_2_img varchar(255),
			primary key(id)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$result = dbDelta( $create );

		insert_into_db();
	}
}

// function to add custom schedule for cron job
function lol_test_cron( $schedules ) {
    $schedules['one_day'] = array(
        'interval' => 86400, // Every 24 hours
        'display'  => __( 'Once Daily' ),
    );
    return $schedules;
} // end lol_test_cron

// adding custom schedule for running after 24 hours
add_filter( 'cron_schedules', 'lol_test_cron' );

// checking if corn job is already scheduled
// if not scheduled, start it from midnight with 24 hour custom schedule we created above
// doing this will trigger the hook after every 24 hours
if ( ! wp_next_scheduled( 'lol_test_cron_hook' ) ) {
    wp_schedule_event( strtotime('midnight'), 'one_day', 'lol_test_cron_hook' );
}

// connecting the cunction which we want to run every 24 hours to the hook
add_action( 'lol_test_cron_hook', 'test_lol_data' );


// to modify the widget make changes after this only

function bs_lolmatches() {
	if(get_the_id() == 75537) {
		
		// adding css for widget
		wp_enqueue_style("bs_lol_widget_css", "/widget/lol/style.css");
			
		add_shortcode('bs_widget_lol', 'display_data');

		// make all changes here, related to design and data of the widget
		function render_widget( $daysToReturn ) {
			global $wpdb;
			$matchDate = '';
			$league = '';

			// query to fetch data from the database
			$matches = $wpdb->get_results( "SELECT * FROM lol_data_" . date("d_m_y", strtotime('-' . $daysToReturn . ' days')) . " ORDER BY match_date, league, match_time_UTC" );
	
			// check if the above query return any data
			if ( $matches != NULL ) {
				// index for iterating over the rows of data fetched from database
				$indexForMatches = 0;
				// loop over the rows
				while( $row = $matches[$indexForMatches] ) {
					// categorising the matches by dates
					if( $matchDate != $row->match_date ) {
						// changing date format
						$dateFormat = strtotime( $row->match_date );
						$newDateFormat = date( "F d, Y", $dateFormat );
						
						echo '<div class="datebar"><span>' . $newDateFormat . '</span></div>';
						$matchDate = $row->match_date;
					}
					
					// categorising the matches by leagues
					if( $league != $row->league ) {
						$league = $row->league;
						echo '<div class="leaguebar"><span>' . $league . '</span></div>';
					}
					
					// changing time format
					$timeFormat = strtotime( $row->match_time_UTC );
					$newTimeFormat = date( "G:i", $timeFormat );
					
					// link for team 1 image
					$team_1_ImgSource = $row->team_1_img;
					//link for team 2 image
					$team_2_ImgSource = $row->team_2_img;
					?>
					<div class="lol-match" >
						<div class="time" >
							<span><?= $newTimeFormat ?></span>
						</div>
							<div class="lol-team lol-team-1">
								<div class="teamImage" >
									<img src = "<?= $team_1_ImgSource ?>" alt="<?= $row->team_1 ?>-logo">
								</div>
								<div class="team-txt" >
									<span><?= $row->team_1 ?></span>
								</div>
							</div>
							<div class="lol-team lol-team-2">
									<div class="teamImage" >
										<img src = "<?= $team_2_ImgSource ?>" alt="<?= $row->team_2 ?>-logo">
									</div>
									<div class="team-txt" >
										<span><?= $row->team_2 ?></span>
									</div>
							</div>
					</div>
					<?php
					$indexForMatches++;
				}
				return;
			} else {
				// echo 'No matches available in the Database. Trying with older matches';
				render_widget( $daysToReturn + 1 );
			}
		}
			
		// This function is to display data from the database
		function display_data() {
			// wordpress database class for connection with databases
			global $wpdb;
			?>
			<div class="lol-upcoming-widget">
				<div class="lol-widgetHeader">
					<h3>Upcoming LOL Matches</h3>
				</div>
				<div class="lol-widgetBody">
					<?php
					// if table NOT found, how many days to return
					$daysToReturn = 0;

					render_widget( $daysToReturn );

					?>
				</div>
				<div class="lol-widget-bottom"></div>
			</div>
		<?php
		}
	}
}

// add_filter('wp_head', 'bs_lolmatches');

// add_shortcode('lol_schedule_delete', 'delete_schedule');

// function delete_schedule() {
// 	wp_clear_scheduled_hook('lol_test_cron_hook');
// }