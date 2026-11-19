=== Beryll Chapter Uploader ===
Contributors: berylltl
Tags: fictioneer, chapters, epub, docx, import, scheduler
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 2.3.0

Import Fictioneer chapters from a ZIP of individual DOCX files or an EPUB. Preview chapters, detect duplicates, publish a chosen number immediately, and schedule the remainder on selected weekdays. Imports run automatically in small batches with a progress indicator.

== Features ==
* Upload a ZIP containing one DOCX per chapter, including DOCX files in nested folders.
* Upload EPUB files and extract chapter documents in EPUB spine reading order.
* Convert supported text formatting to HTML.
* Preview chapters, edit titles and volume/group names, and select or deselect individual chapters.
* Automatically detect volume folders in ZIP uploads (Volume 1, Vol. 2, Book 3, etc.).
* Detect nested volume headings in EPUB navigation where available, with folder-name detection as a fallback.
* Apply one volume/group name to all selected chapters or clear selected group assignments.
* Detect duplicate chapter numbers within the same Fictioneer chapter group/volume; identical chapter numbers in different volumes are treated separately.
* Replace matching chapters within the same volume/group while retaining their existing post IDs and URLs, skip matches, or cancel the import.
* Choose how many selected chapters publish immediately.
* Schedule the remaining chapters on selected weekdays.
* Set the number of chapters published per selected day, first publication date, and publishing time.
* Preview the planned schedule before importing.
* Optionally import all selected chapters as drafts.
* Process imports in groups of five, automatically continuing with a progress indicator.
* Configure the maximum number of chapters per upload from 1 to 200 (default 100).
* Enforce the chapter limit during ZIP/EPUB parsing as well as after extraction.
* Show a summary of published, scheduled, draft, replaced, skipped, and failed chapters.

== Installation ==
1. In WordPress, go to Plugins > Add New Plugin > Upload Plugin.
2. Upload the ZIP file and click Install Now.
3. Activate Beryll Chapter Uploader.
4. Open Chapter Uploader in the WordPress dashboard.

== Notes ==
* Fictioneer stores chapter groups in its native `fictioneer_chapter_group` meta field. Group names are case-sensitive, matching Fictioneer behavior. Leave the group blank for ungrouped chapters.
* Duplicate matching uses the selected story, exact group name, and detected chapter number. If a novel restarts numbering in each volume, assign the correct volume/group to each chapter.
* Fictioneer must be the active theme and its Stories and Chapters post types must be available.
* PHP ZipArchive and DOM/XML support are required.
* Each upload is limited to 15 MB. The default chapter limit is 100 and can be increased to a maximum of 200 in the Chapter Uploader page.
* Chapter import work is processed in groups of five. File upload and initial ZIP/EPUB parsing still happen in the initial request, so very large or complex files may still be affected by hosting execution limits.
* WordPress scheduled posts rely on WP-Cron. On low-traffic hosting, publication may be delayed until the site receives a visit.
* Images embedded in DOCX and EPUB files are not imported.


== Automatic updates ==
* Checks the public Beryll TL GitHub repository for new versions through WordPress.
* Shows updates in the standard WordPress Plugins and Dashboard > Updates screens.
* Uses WordPress' built-in plugin upgrader to download and install the update.
* Existing Fictioneer stories, chapters, and uploader settings are not modified by the updater.
* Update metadata is cached for six hours.
