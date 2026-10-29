=== MemBuild - Community ===
Contributors: membuild
Tags: community, social network, courses, membership, gamification
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.13.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A simple, free community for WordPress: a social feed, reels, stories, messages, courses and gamification in one app.

== Description ==

MemBuild - Community gives your WordPress site its own community app at /community: familiar to anyone who uses social media, simple to run, and free, with no fees on your members.

**Feed**

* Posts with photos, video, files, colored backgrounds and feelings
* YouTube and Vimeo links turn into video cards
* Reactions, threaded comments with photos and emoji, shares
* Latest and Popular sorting, and pinned posts
* Edit your own posts

**Reels and stories**

* Vertical videos with a full-screen viewer; they play on hover in the feed
* Stories that disappear after 24 hours, with replies and reactions

**Classroom**

* Courses, modules and lessons, with videos and downloads
* A course overview before you start: what's inside, how long it takes, who teaches it and who's taking it
* Every course card says where you stand: Start, Continue (and which lesson), Completed, or why it's locked; "Continue where you left off" at the top
* In a lesson: module and lesson number, about how long it takes, its files with type and size; Done moves you to the next lesson, a finished video offers the next one, and finishing a course is celebrated
* Discussions under every lesson, posted like on the feed, with a Helpful vote so the best posts rise; a Discussion tab for the whole course with its own search; pins to a lesson or a course; and a Best answer
* A Resources tab with every file in the course
* Progress tracking and drip schedules
* Course access: open to everyone, to members from a level up, or to member groups you manage
* Member groups can follow a WordPress role, so membership and shop plugins that give buyers a role open your courses automatically
* Lesson videos, downloads and photos in private messages are served only to people allowed to see them

**Events**

* A calendar of live calls, Q&As and workshops: Upcoming and Past, in each member's own time zone
* Going and Interested, with who's going; "Add to calendar" for Google, Apple and Outlook, with the join link inside
* A link to Zoom, Google Meet or anything else opens 15 minutes before the start; a YouTube, Vimeo or Twitch live stream plays inside the community, and becomes the replay afterwards
* Reminders the day before and an hour before, in the app and by email, to everyone going or interested
* Every event is also a post, so members discuss it in the feed; events can be for everyone or for one member group

**Places** (optional, off until you switch it on)

* A list and a map of meetup spots, partners with a member perk, and venues
* "Near me", directions in the phone's own maps app, and who in the community goes there
* Admins and moderators add places; members suggest them for review

**GIFs** (optional, with a free GIPHY API key)

* A GIF button in posts, comments and chats, with GIPHY's trending GIFs and search
* GIFs play as light, looping video, only while on screen

**Search**

* One box for members, posts, comments, courses, lessons and places, following the Classroom's access rules; private messages are never searched

**Gamification**

* Points for taking part, nine levels you can name yourself
* Streaks and a leaderboard

**Messages**

* One-on-one and group chats
* Chat windows docked at the bottom of the screen
* Typing indicators, seen receipts, reactions and photos

**Notifications**

* A notification bell with a live count
* Optional email summaries, sent through your server, Resend or any SMTP service

**Members**

* Profiles, a searchable member directory, bios and profile photos

**Moderation**

* Reports, a review queue, and auto-hiding of posts reported by several members
* Muting and banning, and moderator roles

**Signing in**

* The community's own login, forgot-password and reset-password pages, in your colors (you can switch back to the standard WordPress login in Settings)
* Signed-in members who open your home page can land straight in the community; visitors still see your home page
* A menu of shortcuts to your other pages, managed in Appearance → Menus

**Installable app**

* Members can add the community to their phone's home screen and open it full screen

**Settings**

* Name, logo, accent color, address and feature switches
* Who can post or message, and the points for each action

**Your data, in wp-admin**

* Community → Overview: members, activity this week, posts, comments, storage, reports, and a 30-day activity chart
* Tables of members, posts, comments, files and courses, with search, filters and bulk actions
* CSV exports of everything, and support for WordPress's personal-data export and erase tools
* Private messages are only ever counted, never shown to admins, and their text is stored encrypted

**Optional video hosting**

* Bunny Stream for smooth streaming at scale

Everything runs on your own site, in its own database tables. The app is a small, fast single-page app that loads only what each screen needs.

== Installation ==

1. Upload the plugin and activate it.
2. Visit `yoursite.com/community`. Members sign in with their normal WordPress account.
3. As an admin, open **Community → Settings** in wp-admin (or your avatar menu in the app) to set the name, color, features and permissions.
4. Optional: in **Settings → Email**, connect Resend or another SMTP service for reliable email delivery. In **Settings → Video**, connect Bunny Stream for video hosting.

== Frequently Asked Questions ==

= Does it work with my theme? =

Yes. The community runs as its own app at /community and doesn't depend on your theme.

= Who can join? =

Anyone with an account on your site. Use your usual registration or membership plugin to control who gets an account.

= Can I sell access to courses? =

The plugin doesn't take payments, but it works with plugins that do. Create a member group in Settings → Member groups, link it to the WordPress role your membership or shop plugin gives buyers, and pick that group in a course's "Who can open it". Access follows the role, so it ends when the membership does.

= Does it replace the WordPress login page? =

Members get the community's own login and password-reset pages, and log-out returns there. wp-login.php keeps working, and you can switch back to it in Settings if a security or two-factor plugin needs the standard login screen.

= Where are videos stored? =

By default, on your site: videos are compressed in the member's browser before uploading. For larger communities, connect Bunny Stream in Settings → Video, and videos then upload directly to your Bunny account.

= Are emails sent from my server? =

By default yes, through WordPress's mail (or your SMTP plugin, if you have one). Many hosts deliver email slowly or to spam, so the Email settings let you connect Resend or any SMTP service instead.

= Can I see my community's data in wp-admin? =

Yes. Community → Overview shows what's happening, and the Members, Posts, Comments, Files and Courses screens list everything, with exports to CSV. Admins can't read members' private messages; they only see how many there are.

= Are private messages encrypted? =

Yes, at rest. Message text is encrypted before it's saved, with a key kept outside the database (in `wp-content/mbcom-key.php`, or in wp-config.php if you add it there), so a leaked database or backup can't be read. Keep the key file in your backups and move it with the site: without it, messages can't be read. Message text is never put into emails. This is not end-to-end encryption: someone with full control of your server could still read messages.

= What happens to my data if I delete the plugin? =

Nothing, unless you turn on "Delete all community data when the plugin is deleted" in Settings → Data.

== External services ==

The plugin works without any outside service. These are only contacted when you or your members use the feature named.

**YouTube** (when a member posts a YouTube link)
* What is sent: the video link to YouTube's oEmbed endpoint (`https://www.youtube.com/oembed`), from your server, to get the video's public title, channel and thumbnail. This is refreshed every 25 days, as YouTube's terms allow storing it for at most 30 days.
* Playback: videos play in YouTube's privacy-enhanced player (`youtube-nocookie.com`, controlled through `https://www.youtube.com/iframe_api`) only after a member presses play. Thumbnails load from `i.ytimg.com`.
* Terms: https://www.youtube.com/t/terms
* Privacy: https://policies.google.com/privacy

**Vimeo** (when a member posts a Vimeo link)
* What is sent: the video link to Vimeo's oEmbed endpoint (`https://vimeo.com/api/oembed.json`), from your server, to get the title and thumbnail.
* Playback: videos play in Vimeo's player (`player.vimeo.com`) after a member presses play.
* Terms: https://vimeo.com/terms
* Privacy: https://vimeo.com/privacy

**Gravatar** (WordPress's default profile pictures)
* Members without an uploaded photo show their Gravatar, loaded from `secure.gravatar.com`, as in the rest of WordPress.
* Terms: https://wordpress.com/tos/
* Privacy: https://automattic.com/privacy/

**Resend or another SMTP service** (only if you choose it in Settings → Email)
* What is sent: notification emails (the member's email address, name and the email's content) to the mail service you configured.
* Resend terms: https://resend.com/legal/terms-of-service
* Resend privacy: https://resend.com/legal/privacy-policy

**Bunny Stream** (only if you choose it in Settings → Video)
* What is sent: videos that members upload go from their browser directly to your Bunny Stream library (`video.bunnycdn.com`). Your server creates and deletes videos there, and checks their encoding status, using your library's API key.
* Playback: videos stream from your Bunny CDN hostname.
* Terms: https://bunny.net/tos/
* Privacy: https://bunny.net/privacy/

**OpenStreetMap** (only when Places is on, in Settings → Features; it's off by default)
* Map tiles: members' browsers load the map images from `tile.openstreetmap.org` while they look at Places, as on any site with an OpenStreetMap map. The map shows OpenStreetMap's attribution, as their terms require. Change the tile source with the `membuild_community/map_tiles` filter (for example to a paid provider, for a busy community).
* Address lookup: when an admin or member adds a place and presses Find, your server sends the typed address (and nothing else) to OpenStreetMap's Nominatim service (`nominatim.openstreetmap.org`) to find it on the map. Answers are cached for 30 days. Change the service with the `membuild_community/geocode_url` filter.
* Members' own locations are never sent or stored: "Near me" sorts places in the browser.
* Tile usage policy: https://operations.osmfoundation.org/policies/tiles/
* Nominatim usage policy: https://operations.osmfoundation.org/policies/nominatim/
* Privacy: https://osmfoundation.org/wiki/Privacy_Policy

**GIPHY** (only if you paste a GIPHY API key in Community settings → GIFs)
* What is sent: when a member opens the GIF picker, their browser asks GIPHY (`api.giphy.com`) for trending GIFs or for what they type in the search, with your site's GIPHY key. GIFs then load from GIPHY's servers (`media.giphy.com`) for everyone who sees them. Your site stores only the address of a chosen GIF, never a copy.
* Terms: GIPHY’s API terms, linked from https://developers.giphy.com/docs/api/
* Privacy: https://support.giphy.com/hc/en-us/articles/360032872931-GIPHY-Privacy-Policy

**GitHub** (checking for plugin updates)
* What is sent: twice a day your server asks `https://api.github.com/repos/danicalouwe/membuild-community/releases/latest` for the newest version, and downloads its zip from github.com when you update. Nothing about your site is sent. Turn it off with the `membuild_community/github_updates` filter.
* Terms: https://docs.github.com/site-policy/github-terms/github-terms-of-service
* Privacy: https://docs.github.com/site-policy/privacy-policies/github-general-privacy-statement

**MemBuild** (the plugin's authors: a weekly anonymous report, and updates if you ask for them)
* Always, once a week: a random ID made on your site (not its address), the plugin, WordPress and PHP versions, the site's language and whether it's a multisite, sent to `https://tools.membuild.com/api.php`. Our server also notes the country the request comes from (two letters) and keeps no IP address. This is how we count active installs.
* If "Share usage" is on (ticked by default on the card the Community overview shows once, and changeable in Community settings → Updates & usage): what the community is about and how big it expects to be, which features are on, how sign-up and video are set up, rough counts in ranges ("51-200 members", "11-50 posts"), and the errors caught in the plugin's own code (file, line, a scrubbed message, how often; see Community → Problems), still under the random ID. Never names, email addresses, the site's address, posts, comments, messages or stack traces.
* Only if an admin presses "Keep me updated" (on that card, or in Settings → Updates & usage): the email address they typed, the site's address and name, and the plugin, WordPress and PHP versions, so we can email about new features. While "Share usage" is also on, the community's exact counts (members, members active in the last 30 days, posts, courses, events, which features are on, the last day a member was active) go with it and are refreshed weekly. Never member names, emails, posts, comments or messages. "Stop" removes the email and everything with it from our server.
* Settings → Updates & usage shows exactly what is sent, and "Delete this site's data at MemBuild" removes all of it from our server. The weekly report is not sent from sites whose environment type is local or development. The `membuild_community/usage_reports` filter turns everything off.
* Privacy: https://membuild.com/privacy

The plugin shows no ads and sends nothing else to the plugin's authors.

== Developers ==

Each part of the plugin is a class in `includes/` that registers its own hooks; `includes/class-plugin.php` wires them up. Data lives in custom tables (`{prefix}mbcom_*`) described in `includes/class-install.php`.

**Actions** (all prefixed `membuild_community/`): `post_created`, `post_deleted`, `comment_created`, `comment_deleted`, `reaction_changed`, `reported`, `member_restricted`, `level_up`, `message_sent`, `lesson_completed`, `lesson_uncompleted`, `course_completed`, `story_created`, `story_reacted`, `place_created`, `notification_created`, `access_group_added`, `access_group_removed`, `member_blocked`, `login_after` (under the login form, for other ways to sign in), `app_footer` (the end of the community page, which has no theme or wp_footer). Points and notifications are themselves built on these actions.

**Filters**: `membuild_community/points_rules` (points per action and daily caps), `membuild_community/level_thresholds` (points needed per level), `membuild_community/in_access_group` (decide access-group membership your own way, e.g. from a membership plugin), `membuild_community/send_protected_file` (let your server send protected files, e.g. with X-Accel-Redirect), `membuild_community/daily_upload_bytes` (how much a member may upload per day; 2 GB by default, no limit for admins and moderators), `membuild_community/github_updates` (return false to stop checking GitHub for updates), `membuild_community/usage_reports` (return false to send no reports to MemBuild), `membuild_community/map_tiles` and `membuild_community/geocode_url` (Places' map tiles and address lookup).

**WP-CLI**: `wp mbcom recount-points` (recount totals and levels from the points ledger), `wp mbcom rebuild-points` (replay all activity into points), and `wp mbcom seed` for demo content.

**Encryption key**: private messages and stored passwords are encrypted with a key created in `wp-content/mbcom-key.php`. To keep it in wp-config.php instead, add `define( 'MEMBUILD_COMMUNITY_KEY', '…64 hexadecimal characters…' );` with the same value. Back it up: without it, encrypted messages can't be read. Admins are asked to save a copy (Community settings → Advanced → Encryption key, which also shows and downloads it). Some backup and migration plugins only copy the uploads, plugins and themes folders, not loose files in wp-content, so check yours includes `mbcom-key.php`. If a moved or restored site lost the key, messages pause instead of being locked with a new key, and the saved copy can be pasted back in the same place.

**Protected files on nginx**: lesson files and photos in private messages live in `wp-content/uploads/mbcom-private/`, which Apache and IIS block automatically. On nginx, add `location ^~ /wp-content/uploads/mbcom-private/ { deny all; }` to your site's configuration. Site Health tells you if the folder is reachable from the web.

== Copyright ==

Copyright (C) 2026 Danica Louwe (MemBuild, https://membuild.com). Released under the GNU General Public License, version 2 or later: you may use, change and share it. Copies must keep the copyright notices, and changed copies must say what was changed. "MemBuild" is the author's name and brand, and isn't licensed for other products.

== Credits ==

The community app is built with these open-source libraries, bundled in `assets/build/` (their licences are compatible with the GPL):

* Vue and Vue Router (MIT)
* hls.js, for streaming video (Apache-2.0)
* Mediabunny, for compressing videos in the browser (MPL-2.0)
* Leaflet and Leaflet.markercluster, for the Places map (BSD-2-Clause, MIT)
* Emoji names and categories from emojibase-data (MIT)
* jsPDF, for saving receipts as PDF files (MIT; loaded only when someone saves one)

== Source code ==

The community app is written in Vue and compiled into `assets/build/`. Its full, readable source is included in the `source/` folder, with the build setup (`package.json`, `vite.config.js`). To rebuild it: `cd source && npm install && npx vite build --outDir ../assets/build --emptyOutDir`.

== Screenshots ==

1. The feed, with stories, reels and the post form.
2. Reels.
3. Docked chat windows and the notifications menu.
4. A member profile.
5. Community settings.

== Changelog ==

= 1.13.5 =
* Dark mode fixes: the Places "New" tag and news box, the Search topic icons, a few green status words and the Reels "more" menu no longer show as bright light-mode patches.
* The "Course finished" badge and the Course map's pulse now use your main colour; before, they were always lime.
* A tidier scale: odd in-between text sizes and corners now snap to the nearest standard step, and event titles and member names share one large size. Most screens look the same; a few lines are a pixel larger.
* Shadows come in three depths (small, pop-up, lifted), so menus, toasts and dialogs sit on the page the same way everywhere.
* Groundwork for Community themes: every text size, corner, colour and shadow now comes from one shared set of settings, so a theme can restyle the whole community at once.

= 1.13.4 =
* Search boxes that look inside one section (a lesson's discussion, Messages, the chats panel, and the emoji, GIF and feeling pickers) now match the filters beside them: the same height, the same grey and round ends, a search icon in each, and a thin ring in your main colour while you type.

= 1.13.3 =
* One look for the shapes every page repeats: the ‹ Back link, page titles and the line under them, red error messages, status tags (Active, Paused, Live now, Saved…) and the round close buttons now look the same everywhere.
* Empty pages say so the same way: Billing, Classroom, Events, Leaderboard, Messages, the chats panel and Reels show an icon in a soft circle, a short title, one line on what to do and a full-size button. An owner's "Create your first course" stands out with a dashed edge.
* Status tags use one colour for one meaning (green good, grey neutral, red needs attention, yellow waiting), and Moderation's tags now follow dark mode.

= 1.13.2 =
* Filters everywhere share one look: Classroom, Leaderboard, Events, Home's Latest and Popular, Messages, the chats and Alerts panels, Members, Search, Moderation, Places, a lesson's sort and who reacted. They are smaller than the page's main buttons (they're a choice, not an action), light green when picked, and still easy to tap on a phone.
* The For you list on Home shows its date as a small calendar page, with the month on a grey strip.

= 1.13.1 =
* Sell memberships with FluentCart: in Settings, Memberships, pick the FluentCart product that sells a membership. "Continue to checkout" on a locked course goes straight to a simple checkout in your community's look (your logo and what they get on the left, only the fields that are needed on the right), then a "You're in" page that opens the course. Members are added when the order is paid and removed on a refund. A cancelled subscription keeps access until the end of what was paid for; a paused one closes it until it's resumed.
* Billing for members who pay with FluentCart: You, Billing lists each paid membership with its price, status and next payment. Billing, membership shows the details and every payment, with Pause, Resume and Cancel (it tells you the date you keep access until) right in the community; only a new card is typed on FluentCart's secure page.
* Receipts open in a box over the page, with Save as PDF that downloads a real PDF of the receipt with your logo.
* Keep me logged in now keeps members logged in for a year, renewed while they keep using the community, so the phone app doesn't keep asking. Site admins keep WordPress's 14 days; Log out still ends it at once.
* The account menu is plainer: no small grey lines under each item, the level number on your photo, and "Add to your phone" in place of "Install the app".
* Leaderboard: the top three on a podium with gold, silver and bronze, a short burst of confetti when the page opens and a few quiet pieces after, round rank numbers and points in green pills; your own row stays in sight at the bottom (and at the top once you scroll past) with a soft glow until you reach it. The levels card is a ladder showing how far you've climbed, and How to earn points matches it. Tablets get one tidy column.
* The menu at the top fits at every window size: on tablets and small laptops each section shows its icon with its word underneath, and icons only where even that is too tight.
* Members without a photo, or whose photo won't load, show their initials instead of a broken image.

= 1.13.0 =
* First week: a short "Finish setting up" checklist for the owner (with Invite people) and a welcome checklist for new members, beside the Home feed. New members get a welcome email to confirm their address; activity emails wait until they do.
* Emails: a new, cleaner look for every email, and the activity email shows comments with photos, replies under your comment and the next event. Messages are never copied into emails.
* Log in and Join: two-column pages with the picture, bigger fields, Show password, Keep me logged in, and clearer steps for a forgotten password.
* Home: a quiet "For you" list on the right on computers, with the next event, the course you're in the middle of and new members this week.
* Alerts: grouped into Today, This week and Earlier, with "Unread · 3", and several level-ups in a row shown as one.
* Messages: All, Unread and Groups tabs that reach every chat, more chats as you scroll, Mute this chat (no emails, not in the count), rename a group right in the chat header, and no bottom bar while a chat is open on a phone.
* Reels: a full-screen Create a reel with a live preview, share a YouTube Short from its link, a ⋯ menu with Report and Remove reel, sound and search buttons on the video, and a calm "Getting your video ready" card while a video is prepared.
* Stories: bigger buttons, Seen by with faces, a full-screen Create a story with more backgrounds, including your community's colour.
* Search: Recent searches, Look around, results grouped by kind with counts, events as their own kind, and a full screen on phones.
* Moderation: a clearer page with Needs you, Done and Muted & banned, each report saying who, why and what members said, and Take it down or Leave it up.
* Settings: choose how many levels (3 to 9) and their points, Minimum age as simple choices, a separate Danger box, memberships that open and close, and a simpler Safety key backup with a clear file name.
* See it as a member is now a small round eye: white in your view, dark with the level while you see it as a member.
* Fixes: "&" in email subjects, long emails in the confirm banner, comment reports settled with their post, and many smaller fixes.

= 1.12.0 =
* Events: a new event page with the time where it's held and in your own time, Add to calendar, and a full-page New event form. Events can be online, in person or both, held at one of your Places, and repeat every week, every 2 weeks or every month.
* Places: one big map with the list in a box at the bottom. Members can show themselves on the map by city, faces in the same city group together, and a place's card opens right by its pin. Search finds cities, members and places, and works with the arrow keys.
* Places: share "What's new" at a place (checked by an admin first), see "Coming up here" for events held there, set opening hours day by day, a phone number with its country code, and who can see a place (everyone or a membership). The map opens on the busiest part of your community.
* See it as a member: admins can switch to a member's view, with any membership and level, from the button at the bottom right.
* Leaderboard: This week, This month and All time, where you stand and how many points to pass the next person, your own row when you're further down, more members as you scroll, and Levels & points with the real point rules.
* Pages show a soft grey outline of what's coming while they load, instead of "Loading…", and move in gently.
* Courses: the owner bar is gone; Course settings and All lessons sit beside "Classroom".
* Fixes: grey buttons have dark text everywhere, removing a place keeps its events, ties on the Leaderboard share a place, and many smaller fixes on phones.

= 1.11.1 =
* Reels: the video gets the whole width (no sidebar), Like, Comments and Share sit beside the video, round up and down arrows move between reels, and one "+ New reel" button sits at the top right.
* Reels: on a computer, comments open in a panel on the right while the reel keeps playing.
* New message opens as a small chat window at the bottom right, like Facebook, instead of a big box in the middle.
* GIFs in a chat open in a small box above the message bar, and the GIF button is a simple filled square.
* Moderation: every tab shows how many are in it, and muting or banning someone from a report about their profile closes the report as "Muted by" or "Banned by".

= 1.11.0 =
* A new Members page: cards with each member's cover photo, where they live and when they joined, a green dot on who's online, and more members as you scroll.
* Online status: a green dot shows who's using the community right now. Members can hide it in Privacy (then they don't see others' dots either), and owners can turn it off.
* A new profile page, with Edit profile, Emails you get and Privacy each on a page of their own. Change your photo or cover with the camera on your profile. The unsubscribe link in emails opens a clear page.
* Memberships: open courses to the people in a membership, and give or take one away on a member's profile. A locked course says how to get in.
* Lessons: one green Completed button, and after you finish a lesson a short countdown takes you to the next one (Stay here stops it). See it as a member now works on phones too.
* Lesson files: a coloured icon for each kind of file, a name the owner chooses, downloads named after the course and lesson, and Preview for PDFs and pictures. Only members who can open the course can get them.
* Sharing a finished course shows the course as a card, and you stay on the course with a "Shared to Home" message and View post. Any post that links to a course shows its card.
* Phones: text and buttons are a little smaller so more fits, the member view bar is one line, and the Menu tab opens your menu.
* Chats open on the newest message, the chat menu is tidier, and Messages has the whole width.
* Fixed: Word, Excel and PowerPoint files couldn't be added to lessons.
* Fixed: Delete post and other "Are you sure?" questions did nothing in some browsers. They now use the community's own box.
* Fixed: holding Like on an iPhone selected text instead of opening the reactions.

= 1.10.1 =
* Fixed: in the course builder, Publish course, Delete lesson and Delete section couldn't be pressed in their "Are you sure?" boxes. They work again.

= 1.10.0 =
* A new Classroom: bigger course cards with members, posts, progress and what to do next, 52px filters, "Continue where you left off", and Show more after 12 courses. Owners see Published or Draft and an Edit button on every card, name a new course before it opens, and can see the Classroom as a member.
* A new lesson page: the course is its own space with Overview, Lesson and All posts, previous and next, and one Mark Complete button that turns into Next lesson. Files have their own Download buttons, and you can search the discussion under a lesson.
* A new course Overview: the cover beside what the course is, every lesson with its type, time and files, About this course, and what people are saying.
* A new course builder: Lessons, Course settings and a Course map. It saves by itself and always says so, has a visual text editor (Heading, Bold, Italic, List, Link, Picture), changes the order by dragging or with Move up and Move down, and asks before publishing or deleting in its own boxes.
* The Course map shows the course the way members travel it, with how many are on each lesson right now, who may be stuck, a Message button, and "Show me how it plays out".
* Lessons that wait now count their days from the day a member starts the course, not the day they joined. Members already part-way through keep their dates. A lesson can also open on a date you pick, the same day for everyone.
* Each course can turn questions off, and can have an About this course text.

= 1.9.8 =
* Long messages in a chat now use the width of the chat window, like Messenger. The React and Reply buttons float beside the message when you point at it instead of taking space from it.

= 1.9.7 =
* A GIF posted on its own in Home now keeps its real size and shape (never taller than 360px) instead of stretching across the whole post. Photos are unchanged.

= 1.9.6 =
* GIFs in comments and messages now show at sticker size, no more than 240px on their longest side, the same as a photo in a comment. Tap one to see it full size. GIFs in posts are unchanged.

= 1.9.5 =
* In Home, a lesson question now leads with its course: the course cover is the picture, the member's face sits on its corner, and the line under the course name says who asked, when, and in which lesson. Same card everywhere else.

= 1.9.4 =
* Lesson questions now show in Home for everyone who has started that course, with the lesson named on the card, so the whole class can answer instead of only whoever opens that lesson. Admins see every course's questions. Course posts still stay off profiles, and pins inside a course stay inside the course.

= 1.9.3 =
* On a slow host a message you had just sent could show twice in the chat for a moment, until the send came back. The copy the chat picks up on its own now replaces the pending one straight away.

= 1.9.2 =
* GIFs in posts showed as a blank box until you scrolled or reloaded: GIPHY sends no still image for the picker, so posts saved without a first frame. New GIF posts now save one, older ones load their first frame, and a GIF starts as soon as a sliver of it is on screen.
* Links into the community can open a chat window directly (?chat=ID), used by the demo site to greet new guests.

= 1.9.1 =
* The row checkboxes in the admin tables were clipped by WordPress's em-sized column; they have their own room now.

= 1.9.0 =
* The wp-admin pages redesigned on the MemBuild Product System, the look shared with MemBuild's other plugins: one panel with the product tile, the version ("up to date", or the newer version on offer), nine pill tabs, the page's title and one line, cards, and a one-line footer. Stat tiles, a pill strip, the 30-day chart with its totals as the legend and a hover card per day, the activity list, Export and Problems all on the same components. Members, Posts, Comments, Files and Courses keep WordPress's tables (sorting, bulk actions, Screen Options) in the new look; the Posts table loses the Type column, which the thumbnail already said.
* DM Sans and DM Mono ship inside the plugin (SIL Open Font License), so the admin pages never load a font from Google.
* The "save a copy of your key" reminder now lives on the Community overview only, inside the panel; the "messages are paused" error still shows on every admin screen.
* Settings ↗ and Open community ↗ open the community in a new tab.
* The Problems page's browser stacks keep the file and line; "Copy link" on a comment can no longer throw.

= 1.8.3 =
* Reactions: hover the reaction pill on a post or a comment to see how many of each kind, like Facebook. After you react, the button shows your reaction (a filled thumb for Like, otherwise its face) instead of the plain thumb. One reaction per person, as before.
* Comments follow Facebook groups, not pages: Like · Reply on the row, everything else behind ⋯ (Copy link, Send in a message, Share… on phones, Report, Delete). The thumbs-down is gone; old dislikes were never counted and stay that way.
* A comment's link opens the post with that comment scrolled to and highlighted. In a chat, a link to a post or comment shows as a preview card (who wrote it, the start of it, its picture) that opens it in place; the chat list says "Shared a post" instead of the address.
* Tablets: the section tabs are seven equal cells, icon over word, and never push the right-hand icons off the bar.

= 1.8.2 =
* Search results show the post's picture: a photo, or a video's still with a play badge (reels in their tall shape), with who posted it on the corner. Plain posts keep the author's avatar.

= 1.8.1 =
* On wide screens the Reels page keeps the left column (Home, Classroom, Members and the rest), with the dark stage filling the space beside it; it used to leave an empty strip where the column belonged.

= 1.8.0 =
* New: Problems. Errors in the plugin's own code are caught on your site: PHP crashes in its files, and errors the community app hits in the browser. Community → Problems lists each one once with how often it happened, the file and line, a stack, and a "Copy for support" box; the overview says when something new was caught. Messages are scrubbed before they're stored (emails, links, quoted text, long numbers and server paths are removed) and nothing a member typed is ever kept. Members never see any of it. With "Share usage" on, the weekly report also carries these lines (file, line, scrubbed message, count; never the stack or the site's address), so a version that breaks somewhere is noticed before anyone writes in.

= 1.7.0 =
* New: "Keep me updated". The Community overview asks once (and once more two weeks after "Not now") for an email address to hear about new features; the card also asks what the community is about and whether to share usage, which is ticked by default. While the card is up, the overview's numbers sit blurred behind it; "Not now" clears the way at once, and anything urgent (reports waiting, a broken host) always shows above it.
* The Community overview in wp-admin redesigned: stat cards with icons and "this week" chips, a pill strip, softer panels, all in the admin colour scheme. Settings has a new "Updates & usage" section with the same choice, a Stop button, the usage switch (moved there from Advanced) and a preview of exactly what's sent. See External services for what each choice sends; nothing identifying leaves the site unless an admin presses "Keep me updated".

= 1.6.0 =
* New: Events. Admins and moderators add a live call, a Q&A or a workshop in one dialog: title, date and time, length, a link, details, a cover, and who can see it. Members see times in their own time zone, tap Going or Interested, add it to Google, Apple or Outlook calendar with the join link inside, and get a reminder the day before and an hour before (in the app and by email). The Join button appears 15 minutes before the start. A YouTube, Vimeo or Twitch live stream plays right on the event page, and becomes the replay afterwards; hosts can also paste a recording link. Each event is a post too, so it shows in the feed with its own discussion. Changing the time or cancelling tells everyone going. Duplicate makes next week's copy in two taps. Built for big communities: reminding 2,000 members takes a few queries, and the emails go out in the email job's batches.
* Facebook's desktop layout on wide screens: the sections are icons across the top (their name shows on hover) and the same sections with their words down the left, with your profile at the top and Messages, Settings and Moderation below a line. Laptops keep the word tabs, tablets get an icon with its word underneath, and phones are unchanged.
* The test runner copies the test file into the container instead of passing it on the command line (it had outgrown the limit).

= 1.5.1 =
* Faster Classroom with many members: lesson completions are now indexed by course, so the course list and course pages stay quick (tested with 2,000 members and 128,000 completed lessons: 75 ms down to 5 ms). The Resources tab loads every file size in one query instead of one per lesson.
* Long courses: modules you've finished start folded, and the outline scrolls to the lesson you're on.
* Fix: lesson titles in the Resources tab no longer show underlined.

= 1.5.0 =
* New: a course overview, like Teachable's or Kajabi's. A course you haven't started, have finished, or can't open yet opens on its first page: description, number of lessons, about how long it takes, files, the instructor, who's taking it, the full outline with locks and opening dates, the newest discussion posts, and one button (Start course, Review, or what unlocks it). A course you're part-way through still opens on your next lesson.
* Classroom: each course card says where you stand (Start, Continue with the lesson to take, Completed, or what unlocks it), shows who's taking it and how lively its discussion is, and opens on your next lesson. "Continue where you left off" sits at the top, with All / In progress / Completed filters.
* Lessons say where you are (module, "Lesson 3 of 12", about how many minutes) and list their files with type and size. Done moves you on to the next lesson; when a video ends, the next one starts in five seconds unless you stay. Finishing a course gets a celebration and a one-tap post to the feed.
* New: lesson discussions. Members post under a lesson the same way as on the feed (photos, GIFs, emoji, reactions, replies). A Helpful vote lifts the most useful posts; staff show as Instructor or Moderator. Posts stay inside the course: only members who can open it see them, and they don't appear in the feed or on profiles.
* New: a Discussion tab with every post in the course, each tagged with its lesson, newest first, with its own search of posts and replies. Course posts show in the main search too, for members of the course.
* New: admins and moderators pin posts to a lesson or to a course (three of each). The person who asked, or staff, can mark a reply as the Best answer; it leads the replies and shows under the post.
* New: a Resources tab with every file in the course. Files in lessons that haven't opened yet show with a lock.
* Fix: locked lessons in the course outline no longer show as very tall rows.

= 1.4.2 =
* The emoji button in posts, comments and chats opens the same panel as chat reactions, like Facebook's: above the button with a pointer to it, "Recently used" at the top, search, every emoji by category, and the category in view highlighted. It stays open for several picks. On phones it's a bottom sheet.
* Fix: the emoji list in "Create post" no longer opens inside the window, cut off and covering the text.

= 1.4.1 =
* Chat reactions work like Messenger: a smiley button, the quick six (❤️ 😆 😮 😢 😡 👍) above the message, and a "+" to react with any emoji. Unsend moved into the ⋮ menu. Reactions sit on the bubble's corner.
* The emoji panel floats above the page (no more scrollbars inside the chat window), with a search box, "Your reactions", every emoji by category, and category tabs. Emoji names come from emojibase (MIT) and load only when the panel opens.

= 1.4.0 =
* New: GIFs from GIPHY. Paste a free GIPHY API key in Community settings → GIFs and a GIF button appears in posts, comments and chats: trending GIFs, search as you type, tap to add. Searches go from members' browsers to GIPHY, and nothing is copied to your site.
* New: GIFs play like on Facebook, as GIPHY's silent looping video version (often a tenth of the GIF's size), only while on screen. With "reduce motion" on, they wait for a tap.
* Fix: rate limits (posts, comments, chats, GIFs, place suggestions) now hold when many requests arrive at the same moment; before, a burst could slip past them.

= 1.3.1 =
* Fix: opening a place from a link or from search now flies the map to it, instead of staying on the world view.
* Fix: a place's actions (I go here, Directions, Website, Call) look like buttons again.

= 1.3.0 =
* New: Places (off until you switch it on in Settings → Features). A list and a map of meetup spots, partners with a member perk, and venues: "Near me", directions in the phone's maps app, website and call buttons, and "I go here" with the members who go there. Pins close together merge into a bubble with a count. A place without a photo shows a small map of its spot.
* New: admins and moderators add places; members suggest them, and admins approve suggestions right in the list. An address is found on the map once, when the place is saved, and the pin can be dragged to the exact spot.
* New: search finds places too.
* Places uses OpenStreetMap for the map and the address lookup, with no API keys; see External services.

= 1.2.2 =
* The header spans the full width: logo and search at the left edge, the icons at the right, the tabs centred between them. Below 1200px wide the search box becomes a button, so it never crowds the tabs.

= 1.2.1 =
* "Built by MemBuild" at the foot of the Community pages in wp-admin and of the community's Settings page (admins only; members don't see it).

= 1.2.0 =
* New: search. A box at the top finds members, posts, comments, courses and lessons as you type, with a results page for each kind. It follows the Classroom's access rules: locked courses show their lesson titles with the lock but never their text, courses hidden when locked don't appear, and private messages are never searched. Switch it off in Settings → Features.
* New: a full-text search index on posts and comments, added in the background after updating.
* The copyright notice is now in every file.

= 1.1.0 =
* New: a one-time question on the Community overview (what your community is about, and how big you expect it to be), with an optional "Share anonymous usage with MemBuild".
* New: a weekly anonymous report to MemBuild, so we can count active installs: a random ID and version numbers, nothing about your site or members. Sharing usage adds features and rough counts. See External services, and Community settings → Advanced to see exactly what's sent or delete it.
* Fix: the lock on a course cover now sits in the corner instead of over the title.

= 1.0.0 =
* First release: feed, reels, stories, courses, levels and leaderboard, private messages (encrypted at rest), moderation, the community's own login and sign-up pages, and updates from GitHub releases.
