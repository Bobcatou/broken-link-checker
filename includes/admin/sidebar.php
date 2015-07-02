<!-- Donation button -->
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
			
			<input type="hidden" name="rm" value="2">
			<input type="hidden" name="return" value="<?php 
				echo esc_attr(admin_url('options-general.php?page=link-checker-settings&donated=1')); 
			?>" />
			<input type="hidden" name="cbt" value="<?php 
				echo esc_attr(__('Return to WordPress Dashboard', 'broken-link-checker')); 
			?>" />
			<input type="hidden" name="cancel_return" value="<?php 
				echo esc_attr(admin_url('options-general.php?page=link-checker-settings&donation_canceled=1')); 
			?>" />
			
			<input type="image" src="https://www.sandbox.paypal.com/en_US/GB/i/btn/btn_donateCC_LG.gif" name="submit" alt="PayPal - The safer, easier way to pay online." style="max-width:170px;height:47px;border:0;">
		</form>
	</div>					
</div>

<!-- Other advertising -->
<?php
$configuration = blc_get_configuration();
if ( !$configuration->get('user_has_donated') ):
?>
	<div id="managewp-ad" class="postbox">
		<div class="inside">
			<a href="http://managewp.com/?utm_source=broken_link_checker&utm_medium=Banner&utm_content=mwp250_2&utm_campaign=Plugins" title="ManageWP">
				<img src="<?php echo plugins_url('images/mwp250_2.png', BLC_PLUGIN_FILE) ?>" width="250" height="250" alt="ManageWP">
			</a>
		</div>
	</div>
<?php
endif; ?>