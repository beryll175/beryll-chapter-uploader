# Beryll Chapter Uploader

WordPress plugin for importing chapters into Fictioneer novels, assigning Patreon access levels, scheduling releases, and sending Discord announcements through GitHub Actions.

## What changed in 2.6.0

- Chapter-list group labels are **Free**, **Beryll Treasure**, and **Beryll Vault**.
- New novel publication triggers one orange Discord announcement.
- Newly imported public chapters trigger grouped green announcements.
- Initial Beryll Treasure and Beryll Vault chapters trigger one grouped orange early-access announcement per novel/import.
- Normal chapters scheduled as WordPress future posts are queued for a green announcement when WordPress publishes them.
- Beryll Vault → Beryll Treasure promotions do not announce. Beryll Treasure → Free promotions trigger a green grouped announcement.
- Failed GitHub workflow dispatch requests remain pending and are retried by the hourly scheduler.
- ZIP/EPUB expansion limits reduce archive-bomb risk.

## Repository files

- `beryll-chapter-uploader.php` — plugin source.
- `.github/workflows/discord-notify.yml` — GitHub Actions workflow that posts chapter and novel announcements to Discord.
- `update.json` — metadata used by the plugin's GitHub updater.
- `readme.txt` — WordPress plugin readme.
- `tools/omni2beryll.js` — existing conversion utility.

## Install / update the plugin

This repository ZIP is intended for repository upload and source backup. The plugin's built-in updater downloads the GitHub `main` archive and normalizes its folder name. To update manually, replace the existing plugin source with the files in this repository while preserving the plugin directory name `beryll-chapter-uploader`. Do not delete plugin settings or post metadata.

## GitHub Actions / Discord setup

1. Create two Discord webhook URLs: one for chapter notifications and one for new-novel notifications.
2. In GitHub, open **Settings → Secrets and variables → Actions**. Add repository secrets:
   - `DISCORD_CHAPTER_WEBHOOK`
   - `DISCORD_STORY_WEBHOOK`
3. Confirm `.github/workflows/discord-notify.yml` is present on the `main` branch.
4. In WordPress, open **Chapter Uploader → GitHub Relay**. Configure:
   - GitHub username: `beryll175`
   - Repository: `beryll-chapter-uploader`
   - Workflow: `discord-notify.yml`
   - A fine-grained GitHub token with **Actions: Read and write** access to this repository.
5. Save settings and use **Test GitHub Relay**. Check Discord and the repository's Actions tab.

InfinityFree blocks direct Discord webhook requests. WordPress therefore calls the GitHub Actions workflow; the workflow sends the actual webhook request. A GitHub workflow dispatch response of HTTP 204 confirms that GitHub accepted the run, not that Discord has already received the message. If a run fails after dispatch, inspect the run in the GitHub Actions tab. WordPress retries dispatch failures, but it cannot currently detect a later failure inside an already-accepted workflow run.

## Notification behavior

| Event | Discord notification |
| --- | --- |
| New novel first published | Orange “New Novel!” story embed |
| New public chapters imported | Green grouped chapter embed |
| Initial Beryll Treasure + Beryll Vault chapters | One orange grouped early-access embed |
| Normal WordPress-scheduled chapter becomes published | Green grouped chapter embed |
| Beryll Vault → Beryll Treasure | None |
| Beryll Treasure → Free | Green grouped chapter embed |

Chapters are grouped by novel and release mode. Different novels are never combined. Large chapter batches are split so the Discord embed description remains within Discord's size limit. Replacing an existing chapter does not automatically re-announce it.

## Patreon access model

- **Free:** public access.
- **Beryll Treasure:** Beryll Treasure and Beryll Vault tiers.
- **Beryll Vault:** Beryll Vault tier only.

Configured tier IDs in the current source: Treasure `30102461`; Vault `30102477`. Confirm these IDs against your Fictioneer/Patreon setup before changing tiers.

## Scheduling

- Normal scheduling uses WordPress future publication dates.
- Patreon scheduling is configured separately for each novel.
- All schedule dates and times use the WordPress site timezone.
- The hourly WP-Cron event handles Patreon promotions and retries pending notifications. If one or more scheduled days were missed, the scheduler catches up one due release date per hourly run.
- WP-Cron depends on site visits unless your host provides a real cron facility. Low traffic may delay releases.

## Upload safeguards

- Maximum uploaded ZIP/EPUB size: 15 MB.
- DOCX ZIP archives: maximum 1,000 entries and 100 MB total uncompressed content.
- EPUB archives: maximum 2,000 entries and 150 MB total uncompressed content.
- Configurable chapter limit: up to 200.
- Hidden files, macOS metadata, and temporary Word files are ignored.

## Compatibility

- WordPress 6.0 or later.
- PHP 7.4 or later.
- Fictioneer 5 (uses Fictioneer's `fcn_story` and `fcn_chapter` post types and Patreon metadata).

## Version

`2.6.0`
