<div id="donate" class="postbox">
	<h3 class="hndle"><?php _e('Donate $10, $20 or $50!', 'broken-link-checker'); ?></h3>
	<div class="inside">
		<p><?php
		_e('If you like this plugin, please donate to support development and maintenance!', 'broken-link-checker');							
		?></p>
		
		<form style="text-align: center;" action="https://www.paypal.com/cgi-bin/webscr" method="post">
			<input type="hidden" name="cmd" value="_donations">
			<input type="hidden" name="business" value="G3GGNXHBSHKYC">
			<input type="hidden" name="lc" value="US">
			<input type="hidden" name="item_name" value="Broken Link Checker">
			<input type="hidden" name="no_note" value="1">
			<input type="hidden" name="no_shipping" value="1">
			<input type="hidden" name="currency_code" value="USD">
			<input type="hidden" name="bn" value="PP-DonationsBF:btn_donateCC_LG.gif:NonHosted">
			
			<input type="hidden" name="return" value="<?php 
				echo esc_attr(admin_url('options-general.php?page=link-checker-settings&donated=1')); 
			?>" />
			<input type="hidden" name="cbt" value="<?php 
				echo esc_attr(__('Return to WordPress Dashboard', 'broken-link-checker')); 
			?>" />
			<input type="hidden" name="cancel_return" value="<?php 
				echo esc_attr(admin_url('options-general.php?page=link-checker-settings&donation_canceled=1')); 
			?>" />
			
			<input type="image" src="https://www.sandbox.paypal.com/en_US/GB/i/btn/btn_donateCC_LG.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online." style="max-width:170px;height:47px;">
		</form>
	</div>					
</div>

<style>
#advertising .inside {
	text-align: left;
}
#advertising .inside img {
	display: block;
	margin: 1em auto 0.5em 0;
	border: 0;
}
</style>

<?php
$otherPlugins = array(
	'Google Keyword Tracker' => 'http://wpplugins.com/plugin/876/google-keyword-tracker',
	'Raw HTML' => 'http://wpplugins.com/plugin/850/raw-html-pro',
	'Admin Menu Editor' => 'http://wpplugins.com/plugin/146/admin-menu-editor-pro',
);
?>
<div id="advertising" class="postbox">
	<h3 class="hndle">More plugins by Janis Elsts</h3>
	<div class="inside">
		<ul>
			<?php
			foreach($otherPlugins as $plugin => $url){
				printf(
					'<li><a href="%s">%s</a></li>',
					esc_attr($url),
					$plugin
				);
			}
			?>
		</ul>
	</div>					
</div>
