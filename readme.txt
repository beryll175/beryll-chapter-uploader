=== Beryll Chapter Uploader ===
Contributors: berylltl
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 2.6.0

Import Fictioneer chapters from ZIP/DOCX or EPUB files, detect duplicates, and publish or schedule chapters using normal or Patreon release modes. Discord notifications are relayed through GitHub Actions.

== Features ==
* Import one DOCX per chapter from a ZIP archive.
* Import chapters from EPUB files, including EPUBs containing an entire novel.
* Preview chapter titles and content, detect duplicates, and replace, skip, or cancel.
* Process imports in batches of five and retain progress for interrupted imports.
* Choose Normal Scheduling or Patreon Scheduling for each import.
* Display chapter groups using exactly these names: Free, Beryll Treasure, and Beryll Vault.
* In Patreon mode, publish advance chapters immediately while protecting them with Fictioneer Patreon tier metadata and passwords.
* Use per-novel weekly Patreon schedules and release history.
* Promote Beryll Vault chapters to Beryll Treasure, and Beryll Treasure chapters to Free, in the same scheduled release cycle.
* Send grouped Discord notifications for new novels, new public chapters, initial Patreon early access, scheduled public chapters, and Beryll Treasure-to-Free promotions.
* Retry notifications when GitHub rejects or cannot accept a workflow dispatch.
* Route Discord requests through GitHub Actions because some shared hosts block direct Discord webhook requests.

== Patreon tiers ==
* Beryll Treasure tier ID: 30102461
* Beryll Vault tier ID: 30102477
* Beryll Treasure chapters are accessible to Treasure and Vault members.
* Beryll Vault chapters are accessible to Vault members only.
* Free chapters are public.

== Discord setup ==
1. In GitHub, open the repository Settings → Secrets and variables → Actions.
2. Add DISCORD_CHAPTER_WEBHOOK and DISCORD_STORY_WEBHOOK as repository secrets.
3. In WordPress, open Chapter Uploader → GitHub Relay and save a fine-grained GitHub token with Actions: Read and write permission for this repository.
4. Keep the workflow filename set to discord-notify.yml.
5. Use the Test GitHub Relay button, then check the appropriate Discord channel and the repository Actions tab.

The plugin marks a notification as queued after GitHub accepts the workflow dispatch (HTTP 204). The Discord webhook delivery itself occurs asynchronously in GitHub Actions. If the workflow fails after dispatch acceptance, inspect the Actions run for the specific failure; the WordPress retry queue cannot independently detect a later failure inside GitHub Actions.

== Scheduling notes ==
* WordPress timezone settings are used for release dates and times.
* Both normal scheduled chapters and Patreon promotions rely on WP-Cron.
* WP-Cron runs when the site receives traffic, so low traffic can delay scheduled releases. If your host supports cron jobs, configure a periodic request to wp-cron.php.
* Vault-to-Treasure promotions do not send a Discord notification. Treasure-to-Free promotions send one grouped green notification.
* Chapters in the same novel and release batch are grouped together. Different novels are never combined. Large batches are split into multiple Discord-safe messages.

== Upload limits and safety ==
* Upload size is limited to 15 MB per ZIP or EPUB file by the uploader.
* The DOCX ZIP parser limits archive entries and total uncompressed size. The EPUB parser applies separate entry and uncompressed-size limits.
* The configured chapter limit can be adjusted up to 200.
* Hidden files, macOS metadata folders, and temporary Word files are ignored.

== Changelog ==
= 2.6.0 =
* Added new-novel Discord notifications using the existing story webhook workflow.
* Added grouped notifications for immediate public imports, initial Patreon early access, normal scheduled publications, and Treasure-to-Free promotions.
* Added retry queues for failed GitHub workflow dispatches and per-novel notification locks.
* Standardized chapter-list groups to Free, Beryll Treasure, and Beryll Vault.
* Fixed a missing method boundary that caused a PHP parse error in the previous source.
* Added ZIP and EPUB archive expansion safeguards.
* Corrected update manifest version and documentation.

= 2.5.4 =
* Added normal and Patreon scheduling modes with per-novel Patreon schedules and release history.
