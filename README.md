# Purge Sync for Bunny and LiteSpeed

Clears Bunny when LiteSpeed Cache clears a page or the whole site.

Bunny sits in front of the site address. LiteSpeed clears its own copy with a header Bunny never sees. This plugin sends the same clear to Bunny.

## Install

Download `purge-sync-for-bunny-litespeed-1.1.1.zip` from the [latest release](https://github.com/paramedicspecialist/purge-sync-for-bunny-litespeed/releases/latest). In WordPress, open Plugins → Add New → Upload Plugin and choose that zip.

Then open Settings → Purge Sync and enter the pull zone ID and API key.

## Upgrading from Bunny Sync for LiteSpeed 1.0.x

Install and activate this plugin. On the next admin page load it deactivates the old plugin and removes its folder. Settings, the encrypted API key, and the purge log carry over. If the folder cannot be removed, delete it from the Plugins screen; the settings are kept.

## Remove

Deleting the plugin removes its settings, encrypted API key, purge log, and temporary records. Deactivating it leaves those in place.

## Licence

GPLv3 or later, the same licence used by the official bunny.net plugin. See `LICENSE`.
