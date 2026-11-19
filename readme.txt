=== Beryll Chapter Uploader ===
Contributors: berylltl
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 2.4.0

Import Fictioneer chapters from ZIP/DOCX or EPUB files, detect duplicates and volume groups, assign Patreon advance-access levels, and promote chapters automatically on a weekly schedule.

== Features ==
* Import one DOCX per chapter from ZIP, or chapters from EPUB.
* Preview titles and volume groups; detect and replace/skip duplicates.
* Assign selected chapters to Vault-exclusive, Treasure + Vault, or public access.
* Patreon advance chapters are published immediately with a password and Fictioneer Patreon tier metadata.
* Remaining chapters use the existing immediate/weekday free-publication scheduler.
* Weekly Patreon scheduler promotes Vault-exclusive chapters to Treasure + Vault and Treasure + Vault chapters to Public in the same release run.
* Preserves Fictioneer volume grouping metadata separately from access-level metadata.

== Important notes ==
* Set the correct Patreon tier IDs in the plugin before use if your Patreon campaign changes. Current configured IDs: Treasure 30102461; Vault 30102477.
* Automatic releases use WP-Cron and may run late when the site receives little traffic.
* Public-release Discord announcements are not triggered by this version's Patreon promotion routine. Verify the site's Fictioneer Discord callback/webhook before enabling such announcements.
* Test with a small batch before importing a large library.

== Changelog ==
= 2.4.0 =
* Added batch access distribution for Vault, Treasure + Vault, and Public chapters.
* Added a weekly Patreon release scheduler with simultaneous tier promotions.
* Preserved the existing ZIP/EPUB importer, duplicate handling, and volume-group metadata.
