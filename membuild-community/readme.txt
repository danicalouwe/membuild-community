=== MemBuild - Community ===
Contributors: membuild
Tags: community, social network, courses, membership, gamification
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.4.0
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
* Progress tracking and drip schedules
* Course access: open to everyone, to members from a level up, or to member groups you manage
* Member groups can follow a WordPress role, so membership and shop plugins that give buyers a role open your courses automatically
* Lesson videos, downloads and photos in private messages are served only to people allowed to see them

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

**MemBuild** (the plugin's authors: a weekly anonymous report)
* Always, once a week: a random ID made on your site (not its address), the plugin, WordPress and PHP versions, the site's language and whether it's a multisite, sent to `https://tools.membuild.com/api.php`. Our server also notes the country the request comes from (two letters) and keeps no IP address. This is how we count active installs.
* Only if an admin turns on "Share anonymous usage" (asked once on the Community overview, and in Community settings → Advanced): what the community is about and how big it expects to be, which features are on, how sign-up and video are set up, and rough counts in ranges ("51-200 members", "11-50 posts"). Never names, email addresses, the site's address, posts, comments or messages.
* Settings → Advanced shows exactly what is sent, and "Delete this site's data at MemBuild" removes it from our server. Nothing is sent from sites whose environment type is local or development. Turn it off entirely with the `membuild_community/usage_reports` filter.
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

== Source code ==

The community app is written in Vue and compiled into `assets/build/`. Its full, readable source is included in the `source/` folder, with the build setup (`package.json`, `vite.config.js`). To rebuild it: `cd source && npm install && npx vite build --outDir ../assets/build --emptyOutDir`.

== Screenshots ==

1. The feed, with stories, reels and the post form.
2. Reels.
3. Docked chat windows and the notifications menu.
4. A member profile.
5. Community settings.

== Changelog ==

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
