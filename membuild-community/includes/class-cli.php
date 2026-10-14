<?php
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Development helpers.
 *
 *     wp mbcom seed [--posts=<n>]
 */
class CLI {

	const MEMBERS = array(
		array( 'maya', 'Maya Santos' ),
		array( 'jonah', 'Jonah Reyes' ),
		array( 'priya', 'Priya Nair' ),
		array( 'leo', 'Leo Fischer' ),
		array( 'aiko', 'Aiko Tanaka' ),
		array( 'sam', 'Sam Okafor' ),
	);

	const LINES = array(
		'Just finished module 2 and the "small daily wins" idea finally clicked for me. Anyone else start tracking streaks?',
		'Quick question: how do you all keep momentum on busy weeks? I lose it around Wednesday every time 😅',
		'Sharing my setup for this week. Simple, but it works.',
		'Big thanks to everyone who answered my question yesterday. Tried it this morning and it worked first try!',
		'Who is joining the live call on Thursday? Drop your questions here and I will bring them.',
		'Hot take: consistency beats intensity. Ten minutes a day > two hours on Sunday.',
		'New here! Excited to learn with you all. What is one thing you wish you knew when you started?',
		'Week 3 check-in: down to one cup of coffee and actually sleeping better. Small changes add up.',
		'This community is the reason I didn’t quit last month. Seriously, thank you.',
		'Before and after from this weekend’s project 👇',
		'Reminder: progress photos are allowed to be messy. Post yours!',
		'Tried the challenge from lesson 4. Harder than it looks!',
	);

	const COMMENTS = array( 'Love this!', 'So true 🙌', 'Same here, haha', 'Thanks for sharing', 'This is gold', 'Count me in for Thursday', 'Welcome! 👋', 'Needed this today' );

	const REPLIES = array( 'Agreed 💯', 'Haha yes!', 'Thank you 🥰', 'See you there!', 'Totally', 'Right?! 😂' );

	/**
	 * Seeds demo members, posts, images, reactions and comments.
	 *
	 * ## OPTIONS
	 *
	 * [--posts=<n>]
	 * : Number of posts to create. Default 40.
	 *
	 * [--reset]
	 * : Empty the community tables first.
	 */
	public function seed( $args, $assoc ) {
		global $wpdb;
		$count = (int) ( $assoc['posts'] ?? 40 );
		if ( ! empty( $assoc['reset'] ) ) {
			foreach ( array( 'posts', 'media', 'comments', 'reactions' ) as $t ) {
				$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Install::table( $t ) ) );
			}
			\WP_CLI::log( 'Community tables emptied.' );
		}
		$members = array( get_user_by( 'login', 'admin' )->ID ?? 1 );

		foreach ( self::MEMBERS as list( $login, $name ) ) {
			$user = get_user_by( 'login', $login );
			$members[] = $user ? $user->ID : wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 20 ),
					'user_email'   => $login . '@example.test',
					'display_name' => $name,
					'role'         => 'subscriber',
				)
			);
		}

		$posts_table = Install::table( 'posts' );
		$progress    = \WP_CLI\Utils\make_progress_bar( 'Seeding posts', $count );

		for ( $i = 0; $i < $count; $i++ ) {
			$author  = $members[ array_rand( $members ) ];
			$created = gmdate( 'Y-m-d H:i:s', time() - ( $count - $i ) * wp_rand( 1800, 7200 ) );
			$wpdb->insert(
				$posts_table,
				array(
					'user_id'    => $author,
					'type'       => 'post',
					'content'      => self::LINES[ array_rand( self::LINES ) ],
					'shares_count' => wp_rand( 0, 4 ) ? wp_rand( 0, 30 ) : wp_rand( 100, 400 ),
					'created_at'   => $created,
					'updated_at' => $created,
				)
			);
			$post_id = (int) $wpdb->insert_id;

			if ( 0 === $i % 3 ) {
				$images = 0 === $i % 9 ? 3 : 1;
				for ( $n = 0; $n < $images; $n++ ) {
					self::seed_image( $author, $post_id, $n, $i * 7 + $n );
				}
			}

			foreach ( (array) array_rand( array_flip( $members ), wp_rand( 1, count( $members ) ) ) as $reactor ) {
				$wpdb->insert(
					Install::table( 'reactions' ),
					array(
						'object_type' => 'post',
						'object_id'   => $post_id,
						'user_id'     => $reactor,
						'type'        => wp_rand( 0, 4 ) ? 'like' : Helpers::REACTIONS[ wp_rand( 1, 5 ) ],
						'created_at'  => $created,
					)
				);
			}
			$comments = Install::table( 'comments' );
			for ( $c = wp_rand( 0, 4 ); $c > 0; $c-- ) {
				$commenter = $members[ array_rand( $members ) ];
				$wpdb->insert(
					$comments,
					array(
						'post_id'    => $post_id,
						'user_id'    => $commenter,
						'content'    => self::COMMENTS[ array_rand( self::COMMENTS ) ],
						'created_at' => $created,
					)
				);
				$comment_id = (int) $wpdb->insert_id;

				// Some comments get replies that mention the commenter, Facebook-style.
				for ( $r = wp_rand( 0, 2 ); $r > 0; $r-- ) {
					$wpdb->insert(
						$comments,
						array(
							'post_id'          => $post_id,
							'user_id'          => $members[ array_rand( $members ) ],
							'parent_id'        => $comment_id,
							'reply_to_user_id' => $commenter,
							'content'          => self::REPLIES[ array_rand( self::REPLIES ) ],
							'created_at'       => $created,
						)
					);
				}

				// Reactions on the comment; sometimes from the post's author ("by author").
				$reactors = array_rand( array_flip( $members ), wp_rand( 1, 3 ) );
				foreach ( (array) $reactors as $reactor ) {
					if ( wp_rand( 0, 2 ) ) {
						continue;
					}
					// IGNORE: the author may already have been picked as a reactor for this comment.
					$wpdb->query(
						$wpdb->prepare(
							"INSERT IGNORE INTO %i (object_type, object_id, user_id, type, created_at) VALUES ('comment', %d, %d, %s, %s)",
							Install::table( 'reactions' ),
							$comment_id,
							wp_rand( 0, 2 ) ? $reactor : $author,
							wp_rand( 0, 1 ) ? 'like' : 'love',
							$created
						)
					);
				}
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE %i SET
							replies_count = (SELECT COUNT(*) FROM (SELECT id FROM %i WHERE parent_id = %d) r),
							reactions_count = (SELECT COUNT(*) FROM %i WHERE object_type = 'comment' AND object_id = %d AND type <> 'down')
						WHERE id = %d",
						$comments,
						$comments,
						$comment_id,
						Install::table( 'reactions' ),
						$comment_id,
						$comment_id
					)
				);
			}
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET
						reactions_count = (SELECT COUNT(*) FROM %i WHERE object_type = 'post' AND object_id = %d),
						comments_count = (SELECT COUNT(*) FROM %i WHERE post_id = %d)
					WHERE id = %d",
					$posts_table,
					Install::table( 'reactions' ),
					$post_id,
					Install::table( 'comments' ),
					$post_id,
					$post_id
				)
			);
			$progress->tick();
		}
		$progress->finish();
		\WP_CLI::success( sprintf( 'Seeded %d posts from %d members.', $count, count( $members ) ) );
	}

	/**
	 * Seeds active stories: photo stories (tall gradients drawn locally) and
	 * text stories, some already seen by admin, plus views on admin's own story.
	 *
	 * ## OPTIONS
	 *
	 * [--reset]
	 * : Remove existing stories first.
	 *
	 * @subcommand seed-stories
	 */
	public function seed_stories( $args, $assoc ) {
		global $wpdb;
		$stories = Install::table( 'stories' );
		$views   = Install::table( 'story_views' );
		if ( ! empty( $assoc['reset'] ) ) {
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $stories ) );
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $views ) );
		}
		$admin = get_user_by( 'login', 'admin' );
		$texts = array( 'Morning walk done ☀️', 'Day 12 of the challenge 💪', 'Live call at 7pm, see you there!', 'Small wins count 🎉', 'Coffee + course = perfect Sunday ☕' );
		$bgs   = Helpers::BACKGROUNDS;
		$made  = 0;

		foreach ( array_merge( array( 'admin' ), wp_list_pluck( array_map( fn( $m ) => array( 'l' => $m[0] ), self::MEMBERS ), 'l' ) ) as $i => $login ) {
			$user = get_user_by( 'login', $login );
			if ( ! $user || $i > 5 ) {
				continue;
			}
			$n = 'admin' === $login ? 1 : wp_rand( 1, 3 );
			for ( $k = 0; $k < $n; $k++ ) {
				$age     = wp_rand( 10, 20 * 60 ) * 60; // up to 20 hours old
				$created = gmdate( 'Y-m-d H:i:s', time() - $age );
				$expires = gmdate( 'Y-m-d H:i:s', time() - $age + DAY_IN_SECONDS );
				$row     = array( 'user_id' => $user->ID, 'created_at' => $created, 'expires_at' => $expires, 'duration' => 5 );
				if ( wp_rand( 0, 2 ) ) {
					$media = self::draw_image( $user->ID, null, 1080, 1920, $i * 11 + $k, "story-{$login}-{$k}" );
					$row  += array( 'kind' => 'photo', 'media_url' => $media['url'], 'width' => 1080, 'height' => 1920 );
					$wpdb->update( Install::table( 'media' ), array( 'post_id' => 0 ), array( 'id' => $media['id'] ) );
				} else {
					$row += array( 'kind' => 'text', 'text' => $texts[ array_rand( $texts ) ], 'background' => $bgs[ array_rand( $bgs ) ] );
				}
				$wpdb->insert( $stories, $row );
				$story_id = (int) $wpdb->insert_id;
				++$made;

				if ( 'admin' === $login ) {
					// Others viewed (and some reacted to) admin's story.
					foreach ( array_slice( self::MEMBERS, 0, 5 ) as $j => $m ) {
						$viewer = get_user_by( 'login', $m[0] );
						$wpdb->insert( $views, array( 'story_id' => $story_id, 'user_id' => $viewer->ID, 'reaction' => $j % 2 ? null : Helpers::REACTIONS[ $j % 3 ], 'viewed_at' => $created ) );
					}
				} elseif ( $i >= 4 ) {
					// Admin has already seen these people's stories (grey ring).
					$wpdb->insert( $views, array( 'story_id' => $story_id, 'user_id' => $admin->ID, 'viewed_at' => $created ) );
				}
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET views_count = (SELECT COUNT(*) FROM %i WHERE story_id = %d) WHERE id = %d', $stories, $views, $story_id, $story_id ) );
			}
		}
		\WP_CLI::success( "Seeded {$made} stories." );
	}

	/**
	 * Adds YouTube videos as reels (embedded, never downloaded). Each link is
	 * checked through YouTube's oEmbed endpoint, exactly as when a member pastes it.
	 *
	 * ## OPTIONS
	 *
	 * <url>...
	 * : One or more YouTube Shorts or video links.
	 *
	 * [--user=<login>]
	 * : Who shares them. Default admin.
	 *
	 * @subcommand add-shorts
	 */
	public function add_shorts( $args, $assoc ) {
		global $wpdb;
		$user = get_user_by( 'login', $assoc['user'] ?? 'admin' );
		if ( ! $user ) {
			\WP_CLI::error( 'User not found.' );
		}
		$added = 0;
		foreach ( $args as $i => $url ) {
			$media = Embeds::create( $user->ID, $url );
			if ( is_wp_error( $media ) ) {
				\WP_CLI::warning( "{$url}: " . $media->get_error_message() );
				continue;
			}
			// Space them out so the Reels order looks natural.
			$created = gmdate( 'Y-m-d H:i:s', time() - ( count( $args ) - $i ) * 900 );
			$wpdb->insert(
				Install::table( 'posts' ),
				array( 'user_id' => $user->ID, 'type' => 'reel', 'content' => $media['embed']['title'], 'created_at' => $created, 'updated_at' => $created )
			);
			Media::attach( $user->ID, (int) $wpdb->insert_id, array( $media['id'] ) );
			\WP_CLI::log( "Added: {$media['embed']['title']} (YouTube · {$media['embed']['author']})" );
			++$added;
		}
		\WP_CLI::success( "Added {$added} reels." );
	}

	/**
	 * Seeds demo courses: an onboarding course, a dripped 5-day challenge and a draft.
	 *
	 * ## OPTIONS
	 *
	 * [--reset]
	 * : Remove existing courses first.
	 *
	 * @subcommand seed-courses
	 */
	public function seed_courses( $args, $assoc ) {
		global $wpdb;
		if ( ! empty( $assoc['reset'] ) ) {
			foreach ( array( 'courses', 'modules', 'lessons', 'lesson_progress' ) as $t ) {
				$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Install::table( $t ) ) );
			}
		}
		$admin = get_user_by( 'login', 'admin' );
		$now   = Helpers::now();

		$video = function ( $url ) use ( $admin, $wpdb ) {
			$media = Embeds::create( $admin->ID, $url );
			if ( is_wp_error( $media ) ) {
				\WP_CLI::warning( "{$url}: " . $media->get_error_message() );
				return null;
			}
			$wpdb->update( Install::table( 'media' ), array( 'post_id' => 0 ), array( 'id' => $media['id'] ) );
			return $media['id'];
		};

		$worksheet = function () use ( $admin, $wpdb ) {
			$uploads = wp_upload_dir();
			$name    = wp_unique_filename( $uploads['path'], '90-day-goal-worksheet.txt' );
			$path    = trailingslashit( $uploads['path'] ) . $name;
			file_put_contents( $path, "90-DAY GOAL WORKSHEET\n\n1. My goal in one sentence:\n\n2. Why it matters to me:\n\n3. What I'll do every day (10 minutes is enough):\n\n4. Who I'll tell in the community:\n" );
			$url = trailingslashit( $uploads['url'] ) . $name;
			$wpdb->insert( Install::table( 'media' ), array( 'user_id' => $admin->ID, 'post_id' => 0, 'kind' => 'file', 'url' => $url, 'mime' => 'text/plain', 'created_at' => Helpers::now() ) );
			return array( array( 'media_id' => (int) $wpdb->insert_id, 'name' => '90-day-goal-worksheet.txt', 'url' => $url, 'mime' => 'text/plain' ) );
		};

		$cover = function ( $seed, $name ) use ( $admin, $wpdb ) {
			$media = self::draw_image( $admin->ID, 0, 1280, 720, $seed, $name );
			return $media['url'];
		};

		$courses = array(
			array(
				'title'       => 'Start Here',
				'description' => 'Everything you need for your first week: how the community works, setting your goal, and building the daily habit.',
				'status'      => 'published',
				'cover'       => $cover( 3, 'course-start-here' ),
				'modules'     => array(
					'Welcome' => array(
						array( 'Welcome to the community', "## You made it 👋\n\nThis is where we **show up, share wins, and keep each other going**.\n\nWatch the short video, then introduce yourself in the feed:\n\n- Your name and where you're from\n- One goal for the next 90 days\n- One thing you're great at\n\nSee you inside!", 'https://www.youtube.com/shorts/fpKYApKhEl0', 0, false ),
						array( 'How this community works', "## The three tabs\n\n- **Feed** — share wins, questions and progress.\n- **Classroom** — courses like this one. Tick lessons off as you go.\n- **Reels** — short videos to keep you motivated.\n\nBe kind, be generous, and post something every week.", null, 0, false ),
					),
					'Your first week' => array(
						array( 'Set your 90-day goal', "## One goal, written down\n\nDownload the worksheet below and fill it in. It takes 10 minutes.\n\n1. Write the goal in one sentence\n2. Write why it matters\n3. Pick one daily action\n\nThen post your goal in the feed so we can cheer you on.", 'https://www.youtube.com/shorts/8Vy2bgduL0A', 0, true ),
						array( 'Build the daily habit', "## Small and daily beats big and rare\n\nDo your one action **every day this week**, even on busy days. Ten minutes counts.\n\nCheck in on Friday with how it went.", 'https://www.youtube.com/shorts/7pmUJE14tAs', 0, false ),
					),
				),
			),
			array(
				'title'       => '5-Day Momentum Challenge',
				'description' => 'One short lesson a day for five days. Each day unlocks the morning after the last.',
				'status'      => 'published',
				'cover'       => $cover( 9, 'course-momentum' ),
				'modules'     => array(
					'The challenge' => array(
						array( 'Day 1: Start before you feel ready', "## Today's action\n\nPick the task you've been putting off and do the **first five minutes**. That's it.", 'https://www.youtube.com/shorts/HzJCUSfFA0M', 0, false ),
						array( 'Day 2: Protect your focus', "## Today's action\n\nTurn off notifications for one hour and work on your goal.", 'https://www.youtube.com/shorts/jzCM-vmAPyY', 1, false ),
						array( 'Day 3: Beat procrastination', "## Today's action\n\nName the fear behind the task you keep avoiding. Write it down, then do the task anyway.", 'https://www.youtube.com/shorts/YNwpMMydTjw', 2, false ),
						array( 'Day 4: Failing forward', "## Today's action\n\nShare one thing that didn't work this week, and what you learned, in the feed.", 'https://www.youtube.com/shorts/3SBi9WaFQaA', 3, false ),
						array( 'Day 5: Gratitude and the next step', "## Today's action\n\nWrite three things you're grateful for this week, then set next week's one action.", 'https://www.youtube.com/shorts/IMPfQCnFoZc', 4, false ),
					),
				),
			),
			array(
				'title'       => 'Coaching Masterclass',
				'description' => 'Work in progress: only admins can see this course until it is published.',
				'status'      => 'draft',
				'cover'       => null,
				'modules'     => array( 'Module 1' => array( array( 'Outline', "Draft lesson.", null, 0, false ) ) ),
			),
		);

		$lesson_ids = array();
		foreach ( $courses as $c_order => $c ) {
			$wpdb->insert(
				Install::table( 'courses' ),
				array( 'title' => $c['title'], 'description' => $c['description'], 'cover_url' => $c['cover'], 'status' => $c['status'], 'sort_order' => $c_order, 'created_by' => $admin->ID, 'created_at' => $now, 'updated_at' => $now )
			);
			$course_id = (int) $wpdb->insert_id;
			$m_order   = 0;
			foreach ( $c['modules'] as $m_title => $lessons ) {
				$wpdb->insert( Install::table( 'modules' ), array( 'course_id' => $course_id, 'title' => $m_title, 'sort_order' => $m_order++ ) );
				$module_id = (int) $wpdb->insert_id;
				foreach ( $lessons as $l_order => list( $title, $content, $url, $drip, $with_file ) ) {
					$wpdb->insert(
						Install::table( 'lessons' ),
						array(
							'course_id'  => $course_id,
							'module_id'  => $module_id,
							'title'      => $title,
							'content'    => $content,
							'media_id'   => $url ? $video( $url ) : null,
							'resources'  => $with_file ? wp_json_encode( $worksheet() ) : null,
							'drip_days'  => $drip,
							'sort_order' => $l_order,
							'created_at' => $now,
							'updated_at' => $now,
						)
					);
					$lesson_ids[ $course_id ][] = (int) $wpdb->insert_id;
				}
			}
			\WP_CLI::log( "Course: {$c['title']} ({$c['status']})" );
		}

		// Some progress, so the grid shows partly finished courses.
		$first = reset( $lesson_ids );
		$wpdb->insert( Install::table( 'lesson_progress' ), array( 'user_id' => $admin->ID, 'lesson_id' => $first[0], 'course_id' => array_key_first( $lesson_ids ), 'completed_at' => $now ) );
		\WP_CLI::success( 'Seeded ' . count( $courses ) . ' courses.' );
	}

	/**
	 * Rebuilds every member's points and level from existing activity
	 * (posts, comments, reactions received, finished lessons), applying daily caps.
	 *
	 * @subcommand rebuild-points
	 */
	public function rebuild_points() {
		$result = Points::rebuild();
		\WP_CLI::success( "Replayed {$result['events']} events into {$result['points']} points." );
	}

	/**
	 * Recounts every member's total and level from the points ledger, without
	 * replaying activity. Quick, and safe to run any time.
	 *
	 * @subcommand recount-points
	 */
	public function recount_points() {
		\WP_CLI::success( sprintf( 'Recounted points for %d members.', Points::recount() ) );
	}

	/** Draws a soft gradient image locally with GD and records it as media. */
	private static function draw_image( $user_id, $post_id, $w, $h, $seed, $name ) {
		global $wpdb;
		$img = imagecreatetruecolor( $w, $h );
		$hue = ( $seed * 47 ) % 360;
		list( $r1, $g1, $b1 ) = self::hsl( $hue, 0.55, 0.6 );
		list( $r2, $g2, $b2 ) = self::hsl( ( $hue + 70 ) % 360, 0.6, 0.38 );
		for ( $y = 0; $y < $h; $y++ ) {
			$t = $y / $h;
			imageline( $img, 0, $y, $w, $y, imagecolorallocate( $img, (int) ( $r1 + ( $r2 - $r1 ) * $t ), (int) ( $g1 + ( $g2 - $g1 ) * $t ), (int) ( $b1 + ( $b2 - $b1 ) * $t ) ) );
		}
		imagefilledellipse( $img, (int) ( $w * 0.65 ), (int) ( $h * 0.35 ), (int) ( $w * 0.45 ), (int) ( $w * 0.45 ), imagecolorallocatealpha( $img, 255, 255, 255, 70 ) );
		imagefilledellipse( $img, (int) ( $w * 0.3 ), (int) ( $h * 0.7 ), (int) ( $w * 0.3 ), (int) ( $w * 0.3 ), imagecolorallocatealpha( $img, 255, 255, 255, 95 ) );

		$uploads  = wp_upload_dir();
		$filename = wp_unique_filename( $uploads['path'], "{$name}.jpg" );
		$path     = trailingslashit( $uploads['path'] ) . $filename;
		imagejpeg( $img, $path, 82 );
		imagedestroy( $img );
		$url           = trailingslashit( $uploads['url'] ) . $filename;
		$attachment_id = wp_insert_attachment( array( 'post_title' => $filename, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'guid' => $url ), $path );
		update_post_meta( $attachment_id, '_mbcom_private', 1 ); // keep it out of the public media list
		$wpdb->insert(
			Install::table( 'media' ),
			array( 'user_id' => $user_id, 'post_id' => $post_id, 'kind' => 'image', 'attachment_id' => $attachment_id, 'url' => $url, 'mime' => 'image/jpeg', 'width' => $w, 'height' => $h, 'created_at' => Helpers::now() )
		);
		return array( 'id' => (int) $wpdb->insert_id, 'url' => $url );
	}

	/** Draws a soft gradient image locally with GD, so seeding needs no network. */
	private static function seed_image( $user_id, $post_id, $order, $seed ) {
		global $wpdb;
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return;
		}
		$w      = 1200;
		$h      = 0 === $seed % 2 ? 900 : 1200;
		$img    = imagecreatetruecolor( $w, $h );
		$hue    = ( $seed * 47 ) % 360;
		list( $r1, $g1, $b1 ) = self::hsl( $hue, 0.55, 0.62 );
		list( $r2, $g2, $b2 ) = self::hsl( ( $hue + 60 ) % 360, 0.6, 0.45 );
		for ( $y = 0; $y < $h; $y++ ) {
			$t = $y / $h;
			imageline( $img, 0, $y, $w, $y, imagecolorallocate( $img, (int) ( $r1 + ( $r2 - $r1 ) * $t ), (int) ( $g1 + ( $g2 - $g1 ) * $t ), (int) ( $b1 + ( $b2 - $b1 ) * $t ) ) );
		}
		$white = imagecolorallocatealpha( $img, 255, 255, 255, 70 );
		imagefilledellipse( $img, (int) ( $w * 0.7 ), (int) ( $h * 0.35 ), 420, 420, $white );

		$uploads  = wp_upload_dir();
		$filename = wp_unique_filename( $uploads['path'], "seed-{$post_id}-{$order}.jpg" );
		$path     = trailingslashit( $uploads['path'] ) . $filename;
		imagejpeg( $img, $path, 82 );
		imagedestroy( $img );

		$url           = trailingslashit( $uploads['url'] ) . $filename;
		$attachment_id = wp_insert_attachment(
			array( 'post_title' => $filename, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'guid' => $url ),
			$path
		);
		update_post_meta( $attachment_id, '_mbcom_private', 1 ); // keep it out of the public media list
		$wpdb->insert(
			Install::table( 'media' ),
			array(
				'user_id'       => $user_id,
				'post_id'       => $post_id,
				'kind'          => 'image',
				'attachment_id' => $attachment_id,
				'url'           => $url,
				'mime'          => 'image/jpeg',
				'width'         => $w,
				'height'        => $h,
				'sort_order'    => $order,
				'created_at'    => Helpers::now(),
			)
		);
	}

	private static function hsl( $h, $s, $l ) {
		$c = ( 1 - abs( 2 * $l - 1 ) ) * $s;
		$x = $c * ( 1 - abs( fmod( $h / 60, 2 ) - 1 ) );
		$m = $l - $c / 2;
		$rgb = array( array( $c, $x, 0 ), array( $x, $c, 0 ), array( 0, $c, $x ), array( 0, $x, $c ), array( $x, 0, $c ), array( $c, 0, $x ) )[ (int) ( $h / 60 ) % 6 ];
		return array_map( fn( $v ) => (int) round( ( $v + $m ) * 255 ), $rgb );
	}
}

