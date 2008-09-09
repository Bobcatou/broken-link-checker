=== Broken Link Checker ===
Contributors: whiteshadow
Tags: links, broken, maintenance
Requires at least: 2.0.2
Tested up to: 2.6.2
Stable tag: 0.4.8

This plugin will check your posts for broken links and missing images in background and notify you on the dashboard if any are found. 

== Description ==
This plugin is will monitor your blog looking for broken links and let you know if any are found.

* Checks your posts (and pages) in the background.
* Detects links that don't work and missing images.
* Notifies you on the Dashboard if any are found.
* Makes broken links display differently in posts (optional).
* Link checking intervals can be configured.
* New/modified posts are checked ASAP.
* You can unlink or edit broken links in the *Manage -> Broken Links* tab. 

**How To Use It**
The broken links, if any are found, will show up in a new tab of WP admin panel - Manage -> Broken Links. A notification will also appear on the Dashboard. 

There are several buttons for each broken link - "Details" shows more info about why the link is considered "broken", "Edit Post" does exactly what it says and "Discard" will remove the message about a broken link, but not the link itself (so it will show up again later unless you fix it). Use "Unlink" to actually remove the link from the post. If references to missing images are found, they will be listed along with the links, with "[image]" in place of link text. 

You can modify the available options at Options -> Link Checker. You can also see the current checking status there - e.g. how many posts need to be checked and how many links are in the queue. The plugin runs while you have any page of the WordPress admin panel open.


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
