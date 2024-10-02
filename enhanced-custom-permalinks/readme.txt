=== Enhanced Custom Permalinks ===
Contributors: Tor N. Johnson
Tags: permalink, url, link, address, custom, redirect
Requires at least: 3.0
Tested up to: 6.5.4
Stable tag: 0.1.2

Set custom permalinks on a per-post, per-tag or per-category basis.

== Description ==

This plugin is DEPRECATED! Do NOT use it on new installations. It has been updated for legacy support only and no longer will function for new content creation. If you need custom permalinks beyond what WP supports, consider plugins like permalink-manager.

This plugin is a fork of the Custom-Permalinks plugin.  It has some expanded bug patches AND is able to interoperate with the WP-no-category-base plugin.

This plugin will allow you to set permalinks in a more precise way - in short you can include sub-folders.  A page could have the permalink http://www.example.com/aFolder/anotherFolder/page.html without having to create the intermediate pages or categories aFolder/anotherFolder/.

Be warned: *This plugin is not a replacement for WordPress's built-in permalink system*. Check your WordPress
administration's "Permalinks" settings page first, to make sure that this doesn't already meet your needs.

This plugin is only useful for assigning custom permalinks for *individual* posts, pages, tags or categories. 
It will not apply whole permalink structures, or automatically apply a category's custom permalink to the posts 
within that category.

== Installation ==

1. Unzip the package, and upload `enhanced-custom-permalinks` to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Edit any post, page, tag or category to set a custom permalink.

== Changelog ==
 
0.1.0 Initial Release

0.1.1 Patch
Updates to allow the plugin to work with newer versions of php w/o warnings by removing calls to mysql_real_escape_string and replacing them with prepared statements.

0.1.2 Patch
Updated code to support modern php and allow older sites to be migrated.