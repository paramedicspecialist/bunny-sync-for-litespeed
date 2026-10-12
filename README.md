# Bunny Sync for LiteSpeed

Clears Bunny when LiteSpeed Cache clears a page or the whole site.

Bunny sits in front of the site address. LiteSpeed clears its own copy with a header Bunny never sees. This plugin sends the same clear to Bunny.

## Install

Download `bunny-sync-for-litespeed-1.0.1.zip` from the [latest release](https://github.com/paramedicspecialist/bunny-sync-for-litespeed/releases/latest). In WordPress, open Plugins → Add New → Upload Plugin and choose that zip.

Then open Settings → Bunny Sync and enter the pull zone ID and API key.

## Remove

Deleting the plugin removes its settings, encrypted API key, purge log, and temporary records. Deactivating it leaves those in place.

## Licence

GPLv2 or later. See `readme.txt`.
