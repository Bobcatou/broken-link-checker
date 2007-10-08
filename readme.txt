=== Broken Link Checker ===
Contributors: whiteshadow
Tags: links, broken, maintenance
Requires at least: 2.0.2
Tested up to: 2.3
Stable tag: 0.1

This plugin will check your posts for broken links in background and notify you on the dashboard if any are found. It runs while any page of WP admin panel is open.

== Description ==

Sometimes, links get broken. A page is deleted, a subdirectory forgotten, a site moved to a different domain. Most likely some of your blog posts contain links. It is almost inevitable that over time some of them will start giving the 404 Not Found error. Obviously you don't want your readers to be annoyed by clicking a link that leads nowhere.  You can check the links yourself but that might be quite a task if you have a lot of posts. You could use your webserver's stats but that only works for local links. So I've made a plugin for WordPress that will check your posts (and pages) in the background, looking for broken links, and let you know if any are found.

The broken links, if any are found, will show up in a new tab of WP admin panel - Manage -> Broken Links. There are several buttons for each broken link - "View" and "Edit Post" do exactly what they say and "Discard" will remove the message about a broken link, but not the link itself (so it will show up again later unless you fix it).

You can modify the few available options at Options -> Link Checker. You can see the current checking status there, too - e.g. how many posts need to be checked and how many links are in the queue.

The plugin runs while you have any page of the WordPress admin panel open.


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
