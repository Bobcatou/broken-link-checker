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

<div id="advertising" class="postbox">
	<h3 class="hndle"><?php _e('Recommended', 'broken-link-checker'); ?></h3>
	<div class="inside" style="text-align: center;">
		<a href="http://www.maxcdn.com/wordpress-cdn.php?type=banner&&affId=102167&&img=c_160x600_maxcdn_simple.gif">
			<img src="<?php echo esc_attr(plugins_url('images/maxcdn.gif', BLC_PLUGIN_FILE)); ?>" border=0>
		</a>
		<img src="http://impression.clickinc.com/impressions/servlet/Impression?merchant=70291&&type=impression&&affId=102167&&img=c_160x600_maxcdn_simple.gif" style="display:none" border=0>
	</div>					
</div>
