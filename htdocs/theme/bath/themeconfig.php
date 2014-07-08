<?php
/**
* Bath theme - mostly copied from default, some images different and static/styles.css 
*/

$theme = new StdClass;

$theme->displayname = 'Bath';
$theme->parent      = 'raw';


//show red warning alert banner if config.php:$cfg->alert is set
if (get_config('alert') && !function_exists('local_header_top_content'))
{
	function local_header_top_content()
	{
		return '
	<div class="warning">
		<p><img src="'.get_config('wwwroot').'theme/raw/static/images/icon_problem.gif" alt="alert">'.get_config('alert').'</p>
	</div>
	';
	}
}
