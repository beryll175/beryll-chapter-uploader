=== Beryll Chapter Uploader ===
Contributors: berylltl
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 2.5.2

Import Fictioneer chapters from ZIP/DOCX or EPUB files, detect duplicates and volume groups, and choose normal or Patreon release handling.

== Features ==
* Import one DOCX per chapter from ZIP, or chapters from EPUB.
* Preview titles and volume groups; detect and replace/skip duplicates.
* Choose Normal Scheduling or Patreon Scheduling on each import.
* In Patreon mode, allocate selected chapters to Vault-exclusive, Treasure + Vault, and Public access.
* Publish advance chapters immediately with password protection and Fictioneer Patreon tier metadata.
* Configure independent weekly Patreon release schedules for each novel, including start date, days, time, and chapters per tier per release.
* Promote Vault-exclusive chapters to Treasure + Vault and Treasure + Vault chapters to Public in the same release run.
* Preserve Fictioneer volume grouping metadata separately from access-level metadata.
* Retain the existing normal immediate/weekday free-publication scheduler.

== Important notes ==
* Current Patreon tier IDs: Treasure 30102461; Vault 30102477.
* Automatic releases use WP-Cron and may run late when the site receives little traffic.
* Chapter ordering currently uses the volume/group label (natural order), then the chapter number parsed from the title. Chapters without a parseable number fall back to post ID order.
* Patreon promotions clear the password and tier locks when a chapter becomes public. A custom Discord announcement callback for these delayed promotions is not yet implemented or verified; do not assume a Discord announcement will be sent at public promotion.
* Test with a small batch and a staging copy before enabling automatic releases on the live site.

== Changelog ==
= 2.5.0 =
* Added a clear Normal Scheduling / Patreon Scheduling choice during import.
* Moved Patreon schedules to independent per-novel settings and release history.
* Added per-novel start dates and kept schedule changes separate from queued chapters.
* Changed promotion ordering to use volume/group and chapter number instead of publication timestamps.
* Corrected the Patreon Scheduler submenu callback.
* Fixed the normal schedule preview to preview only the remaining normal/free chapters.

= 2.4.0 =
* Added batch access distribution for Vault-exclusive, Treasure + Vault, and Public chapters.
* Added a weekly Patreon release scheduler with simultaneous tier promotions.
* Preserved the ZIP/EPUB importer, duplicate handling, and volume-group metadata.
