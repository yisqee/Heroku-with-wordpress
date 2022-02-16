<?php
// 注册接口
function wpjam_register_json($name, $args=[]){
	if(WPJAM_JSON::get($name)){
		trigger_error('API 「'.$name.'」已经注册。');
	}

	return WPJAM_JSON::register($name, $args);
}

function wpjam_register_api($name, $args=[]){
	return wpjam_register_json($name, $args);
}

function wpjam_get_json_object($name){
	return WPJAM_JSON::get($name);
}

function wpjam_get_api($name){
	return wpjam_get_json_object($name);
}

function wpjam_is_json_request(){
	return WPJAM_JSON::is_request();
}

function wpjam_get_json(){
	return wpjam_get_current_json();
}

function wpjam_get_current_json(){
	return WPJAM_JSON::get_current();
}


// 注册后台选项
function wpjam_register_option($name, $args=[]){
	$args	= is_callable($args) ? call_user_func($args, $name) : $args;
	$args	= apply_filters('wpjam_register_option_args', $args, $name);

	if(!isset($args['sections']) && !isset($args['fields'])){
		$args	= ['sections'=>$args];
	}

	return WPJAM_Option_Setting::register($name, $args);
}

function wpjam_unregister_option($name){
	WPJAM_Option_Setting::unregister($name);
}


// 添加后台菜单
function wpjam_add_menu_page($menu_slug, $args=[]){
	if(is_admin()){
		WPJAM_Menu_Page::add($menu_slug, $args);
	}else{
		if(isset($args['function']) && $args['function'] == 'option'){
			if(!empty($args['sections']) || !empty($args['fields'])){
				$option_name	= $args['option_name'] ?? $menu_slug;

				wpjam_register_option($option_name, $args);
			}
		}
	}
}

// 注册 Meta 类型
function wpjam_register_meta_type($name, $args=[]){
	$object	= WPJAM_Meta_Type::get($name);
	$object	= $object ?: WPJAM_Meta_Type::register($name, $args);

	$table_name	= sanitize_key($name).'meta';

	$GLOBALS['wpdb']->$table_name = $object->get_table();

	return $object;
}

function wpjam_get_meta_type_object($name){
	return WPJAM_Meta_Type::get($name);
}


// 注册文章类型
function wpjam_register_post_type($name, $args=[]){
	return WPJAM_Post_Type::register($name, $args);
}


// 注册文章选项
function wpjam_register_post_option($meta_box, $args=[]){
	if(WPJAM_Post_Option::get($meta_box)){
		trigger_error('Post Option 「'.$meta_box.'」已经注册。');
	}

	return WPJAM_Post_Option::register($meta_box, $args);
}

function wpjam_unregister_post_option($meta_box){
	WPJAM_Post_Option::unregister($meta_box);
}

function wpjam_register_posts_column($name, ...$args){
	if(is_admin()){
		$field	= is_array($args[0]) ? $args[0] : ['title'=>$args[0], 'column_callback'=>($args[1] ?? null)];

		return wpjam_register_list_table_column($name, array_merge($field, ['screen_base'=>'edit']));
	}
}


// 注册分类模式
function wpjam_register_taxonomy($name, $args=[]){
	return WPJAM_Taxonomy::register($name, $args);
}

// 注册分类选项
function wpjam_register_term_option($key, $args=[]){
	if(WPJAM_Term_Option::get($key)){
		trigger_error('Term Option 「'.$key.'」已经注册。');
	}

	if(!is_callable($args) && !isset($args['taxonomy'])){
		if($taxonomies = wpjam_array_pull($args, 'taxonomies')){
			$args['taxonomy']	= (array)$taxonomies;
		}
	}

	return WPJAM_Term_Option::register($key, $args);
}

function wpjam_unregister_term_option($key){
	WPJAM_Term_Option::unregister($key);
}

function wpjam_register_terms_column($name, ...$args){
	if(is_admin()){
		$field	= is_array($args[0]) ? $args[0] : ['title'=>$args[0], 'column_callback'=>($args[1] ?? null)];

		return wpjam_register_list_table_column($name, array_merge($field, ['screen_base', 'edit-tags']));
	}
}

// 注册 LazyLoader
function wpjam_register_lazyloader($name, $args){
	$object	= WPJAM_Lazyloader::get($name);

	return $object ?: WPJAM_Lazyloader::register($name, $args);
}

function wpjam_get_lazyloader($name){
	return WPJAM_Lazyloader::get($name);
}

function wpjam_lazyload($name, $ids, ...$args){
	if(in_array($name, ['comment_meta', 'term_meta'])){
		$lazyloader	= wp_metadata_lazyloader();
		$lazyloader->queue_objects(str_replace('_meta', '', $name), $ids);
	}else{
		if($lazyloader = wpjam_get_lazyloader($name)){
			$lazyloader->queue_objects($ids, ...$args);
		}
	}
}

// 注册 AJAX
function wpjam_register_ajax($name, $args){
	$object	= wpjam_get_ajax_object($name);
	$object	= $object ?: WPJAM_AJAX::register($name, $args);

	add_action('wp_ajax_'.$name, [$object, 'callback']);

	if($object->nopriv){
		add_action('wp_ajax_nopriv_'.$name, [$object, 'callback']);
	}

	return $object;
}

function wpjam_get_ajax_object($name){
	return WPJAM_AJAX::get($name);
}

function wpjam_get_ajax_data_attr($name, $data=[], $return=''){
	if($object = wpjam_get_ajax_object($name)){
		return $object->get_data_attr($data, $return);
	}

	return $return == '' ? '' : [];
}

function wpjam_ajax_enqueue_scripts(){
	WPJAM_AJAX::enqueue_scripts();
}

// 注册 map_meta_cap
function wpjam_register_capability($capability, $map_meta_cap){
	return WPJAM_Capability::register($capability, ['map_meta_cap'=>$map_meta_cap]);
}


// 注册验证 txt
function wpjam_register_verify_txt($key, $args){
	return WPJAM_Verify_TXT::register($key, $args);
}

function wpjam_get_verify_txt_object($key){
	return WPJAM_Verify_TXT::get($key);
}


// 注册平台
function wpjam_register_platform($key, $args){
	return WPJAM_Platform::register($key, $args);
}

function wpjam_is_platform($platform){
	return WPJAM_Platform::get($platform)->verify();
}

function wpjam_get_current_platform($platforms=[], $type='key'){
	return WPJAM_Platform::get_current($platforms, $type);
}

function wpjam_get_current_platforms(){
	return WPJAM_Path::get_platforms();
}

// 注册路径
function wpjam_register_path($page_key, ...$args){
	if(count($args) == 2){
		$item	= $args[1]+['path_type'=>$args[0]];
		$args	= [$item];
	}else{
		$args	= $args[0];
		$args	= wp_is_numeric_array($args) ? $args : [$args];
	}

	$object	= WPJAM_Path::get($page_key);
	$object	= $object ?: WPJAM_Path::register($page_key, []);

	foreach($args as $item){
		$type	= wpjam_array_pull($item, 'path_type');

		// if($object->get_type($path_type)){
		// 	trigger_error('Path 「'.$page_key.'」的「'.$path_type.'」已经注册。');
		// }

		$object->add_type($type, $item);
	}

	return $object;
}

function wpjam_unregister_path($page_key, $path_type=''){
	if($path_type){
		if($path_obj = WPJAM_Path::get($page_key)){
			$path_obj->remove_type($path_type);
		}
	}else{
		WPJAM_Path::unregister($page_key);
	}
}

function wpjam_get_path_object($page_key){
	return WPJAM_Path::get($page_key);
}

function wpjam_get_paths($path_type){
	return WPJAM_Path::get_by(['path_type'=>$path_type]);
}

function wpjam_get_tabbar_options($path_type){
	return WPJAM_Path::get_tabbar_options($path_type);
}

function wpjam_get_path_fields($path_type, $for=''){
	return WPJAM_Path::get_path_fields($path_type, $for);
}

function wpjam_get_page_keys($path_type){
	return WPJAM_Path::get_page_keys($path_type);
}

function wpjam_get_path($path_type, $page_key, $args=[]){
	$path_obj	= wpjam_get_path_obj($page_key);

	return $path_obj ? $path_obj->get_path($path_type, $args) : '';
}

function wpjam_parse_path_item($item, $path_type, $parse_backup=true){
	$parsed	= WPJAM_Path::parse_item($item, $path_type);

	if(empty($parsed) && $parse_backup && !empty($item['page_key_backup'])){
		$parsed	= WPJAM_Path::parse_item($item, $path_type, true);
	}

	return $parsed ?: ['type'=>'none'];
}

function wpjam_validate_path_item($item, $path_types){
	return WPJAM_Path::validate_item($item, $path_types);
}

function wpjam_get_path_item_link_tag($parsed, $text){
	return WPJAM_Path::get_item_link_tag($parsed, $text);
}

function wpjam_register_theme_upgrader($upgrader_url){
	$object	= WPJAM_Theme_Upgrader::register(get_template(), ['upgrader_url'=>$upgrader_url]);

	add_filter('site_transient_update_themes',	[$object, 'filter_site_transient']);
}