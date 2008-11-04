<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<title><?php Options::out('title'); ?> &middot; <?php echo $admin_title; ?></title>

	<script src="<?php Site::out_url('scripts'); ?>/jquery.js" type="text/javascript"></script>
	<script src="<?php Site::out_url('scripts'); ?>/ui.core.js" type="text/javascript"></script>
	<script src="<?php Site::out_url('scripts'); ?>/ui.slider.js" type="text/javascript"></script>
	<script src="<?php Site::out_url('scripts'); ?>/ui.tabs.js" type="text/javascript"></script>
	<script src="<?php Site::out_url('scripts'); ?>/ui.sortable.js" type="text/javascript"></script>
	<script src="<?php Site::out_url('scripts'); ?>/ui.resizable.js" type="text/javascript"></script>
	<script src="<?php Site::out_url('scripts'); ?>/jquery.spinner.js" type="text/javascript"></script>
	<script src="<?php Site::out_url('scripts'); ?>/jquery.color.js" type="text/javascript"></script>
	<script src="<?php Site::out_url('habari'); ?>/3rdparty/humanmsg/humanmsg.js" type="text/javascript"></script>
	<script src="<?php Site::out_url('habari'); ?>/3rdparty/hotkeys/jquery.hotkeys.js" type="text/javascript"></script>
	<script type="text/javascript">
	var habari = {
		url: {
			habari: '<?php Site::out_url('habari'); ?>',
			ajaxDashboard: '<?php echo URL::get('admin_ajax', array('context' => 'dashboard')); ?>',
			ajaxDelete: '<?php echo URL::get('admin_ajax', array('context' => 'delete_entries')); ?>',
			ajaxLogDelete: '<?php echo URL::get('admin_ajax', array('context' => 'delete_logs')); ?>',
			ajaxUpdateUsers: '<?php echo URL::get('admin_ajax', array('context' => 'update_users')); ?>',
			ajaxUpdateComment: '<?php echo URL::get('admin_ajax', array('context' => 'update_comment')); ?>',
			ajaxInEdit: '<?php echo URL::get('admin_ajax', array('context' => 'in_edit')); ?>'
		}
	};
	</script>
	<script src="<?php Site::out_url('admin_theme'); ?>/js/media.js" type="text/javascript"></script>
	<script src="<?php Site::out_url('admin_theme'); ?>/js/admin.js" type="text/javascript"></script>

	<?php
		Plugins::act( 'admin_header', $this );
		Stack::out( 'admin_stylesheet', '<link rel="stylesheet" type="text/css" href="%s" media="%s">'."\r\n" );
		Stack::out( 'admin_header_javascript', '<script src="%s" type="text/javascript"></script>'."\r\n" );
	?>
	<!--[if IE 7]>
	<link rel="stylesheet" type="text/css" href="<?php Site::out_url('admin_theme'); ?>/css/ie.css" media="screen">
	<![endif]-->

</head>
<body class="page-<?php echo $admin_page; ?>">

<div id="menubar">

	<div id="menu" class="dropbutton">
		<h1 id="menubutton"><a href="#"><?php echo $admin_page; ?> <span class="hotkey">Q</span></a></h1>

		<div id="menulist" class="dropbuttonlist">
			<ul>
			<?php foreach($mainmenu as $menu_id => $menu): ?>
				<li id="link-<?php echo $menu_id ?>" class="<?php if($menu['selected'] == TRUE) { echo 'selected'; } ?><?php if(isset($menu['submenu'])): ?> submenu<?php endif; ?>" title="<?php echo $menu['title']; ?>"><a class="top" href="<?php echo $menu['url']; ?>"><?php echo $menu['text']; ?> 
				<?php if(isset($menu['hotkey']) && $menu['hotkey'] != ''): ?><span class="hotkey"><?php echo $menu['hotkey']; ?></span><?php endif; ?>
				</a>
				<?php if(isset($menu['submenu'])): ?>
				<ul class="submenu">
				 <?php foreach($menu['submenu'] as $submenu_id => $submenu_item): ?> 
				 	<li id="link-<?php echo $submenu_id ?>" title="<?php echo $submenu_item['title']; ?>" class="sub<?php if(isset($submenu_item['hotkey']) && $submenu_item['hotkey'] != ''): ?> hotkey-<?php echo $submenu_item['hotkey']; ?><?php endif; ?>"><a href="<?php echo $submenu_item['url']; ?>"><?php echo $submenu_item['text']; ?> 
				 	<?php if(isset($submenu_item['hotkey']) && $submenu_item['hotkey'] != ''): ?><span class="hotkey"><?php echo $submenu_item['hotkey']; ?></span><?php endif; ?> 
				 	</a></li> 
				 <?php endforeach; ?> 
				 </ul>
				<?php endif; ?>
				</li>
			<?php endforeach; ?>
			</ul>
		</div>
	</div>

	<a href="<?php Site::out_url('habari'); ?>" id="site" title="<?php _e('Go to Site'); ?>"><?php Options::out('title'); ?></a>

</div>

<div id="spinner"></div>

<div id="page">

<?php Plugins::act('admin_info', $theme, $page); ?>

