=== Broken Link Checker ===
Contributors: whiteshadow
Tags: links, broken, maintenance, blogroll, custom fields, admin
Requires at least: 2.7.0
Tested up to: 2.8
Stable tag: 0.5.2

This plugin will check your posts, custom fields and the blogroll for broken links and missing images and notify you on the dashboard if any are found. 

== Description ==
This plugin will monitor your blog looking for broken links and let you know if any are found.

**Features**

* Monitors links in your posts, pages, the blogroll, and custom fields (optional).
* Detects links that don't work and missing images.
* Notifies you on the Dashboard if any are found.
* Also detects redirected links.
* Makes broken links display differently in posts (optional).
* Link checking intervals can be configured.
* New/modified posts are checked ASAP.
* You view broken links, redirects, and a complete list of links used on your site, in the *Tools -> Broken Links* tab. 
* Each link can be edited or unlinked directly via the plugin's page, without manually editing each post.

**Basic Usage**
Once installed, the plugin will begin parsing your posts, bookmarks (AKA blogroll), etc and looking for links. Depending on the size of your site this can take a few minutes or even several hours. When parsing is complete the plugin will start checking each link to see if it works. Again, how long this takes depends on how big your site is and how many links there are. You can monitor the progress and set various link checking options in *Settings -> Link Checker*.

Note : Currently the plugin only runs when you have at least one tab of the Dashboard open. Cron support will likely be added in a later version.

The broken links, if any are found, will show up in a new tab of WP admin panel - *Tools -> Broken Links*. A notification will also appear in the "Broken Link Checker" widget on the Dashboard. To save display space, you can keep the widget closed and configure it to expand automatically when problematic links are detected.

The "Broken Links" tab will by default display broken links that have been detected so far. However, you can use the subnavigation links on that page to view redirects or see a listing of all links - working or not - instead.

There are several actions associated with each link listed - 

* "Details" shows more info about the link. You can also toggle link details by clicking on the "link text" cell.
* "Edit URL" lets you change the URL of that link. If the link is present in more than one place (e.g. both in a post and in the blogroll) then all instances of that URL will be changed.
* "Unlink" removes the link but leaves the link text intact.
* "Exclude" adds the link's URL to the exclusion list. Excluded URLs won't be checked again.
* "Discard" lets you manually mark the link as valid. This is useful if you know it was detected as broken only due to a temporary glitch or similar. The link will still be checked normally later.

== Installation ==

To do a new installation of the plugin, please follow these steps

1. Download the broken-link-checker.zip file to your local machine.
1. Unzip the file 
1. Upload `broken-link-checker` folder to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress

That's it.

To upgrade your installation

1. De-activate the plugin
1. Get and upload the new files (do steps 1. - 3. from "new installation" instructions)
1. Reactivate the plugin. Your settings should have been retained from the previous version.
