<?php
class WPJAM_Term{
	private $id;
	private $level	= null;

	private function __construct($id){
		$this->id	= (int)$id;
	}

	public function __get($key){
		if(in_array($key, ['id', 'term_id'])){
			return $this->id;
		}elseif($key == 'term'){
			return get_term($this->id);
		}elseif($key == 'thumbnail'){
			return apply_filters('wpjam_term_thumbnail_url', '', $this->term);
		}elseif($key == 'ancestors'){
			if(is_taxonomy_hierarchical($this->taxonomy)){
				return get_ancestors($this->id, $this->taxonomy, 'taxonomy');
			}else{
				return [];
			}
		}else{
			return $this->term->$key;
		}
	}

	public function __isset($key){
		return $this->$key !== null;
	}

	public function get_thumbnail_url($size='full', $crop=1){
		return $this->thumbnail ? wpjam_get_thumbnail($this->thumbnail, $size, $crop) : '';
	}

	public function get_level(){
		if(is_null($this->level)){
			$this->level	= $this->parent ? count($this->ancestors) : 0;
		}

		return $this->level;
	}

	private function get_children($children_terms=null, $max_depth=-1, $depth=0){
		$children	= [];

		if($children_terms && isset($children_terms[$this->id]) && ($max_depth == 0 || $max_depth > $depth+1)){
			foreach($children_terms[$this->id] as $child){
				$children[]	= self::get_instance($child)->parse_for_json($children_terms, $max_depth, $depth+1);
			}
		}

		return $children;
	}

	public function parse_for_json($children_terms=null, $max_depth=-1, $depth=0){
		$json	= [];

		$json['id']				= $this->id;
		$json['taxonomy']		= $this->taxonomy;
		$json['name']			= html_entity_decode($this->name);
		$json['count']			= (int)$this->count;
		$json['description']	= $this->description;

		$tax_obj	= get_taxonomy($this->taxonomy);

		if($tax_obj->public || $tax_obj->publicly_queryable || $tax_obj->query_var){
			$json['slug']	= $this->slug;
		}

		if($tax_obj->hierarchical){
			$json['parent']	= $this->parent;

			if($max_depth != -1){
				$json['children']	= $this->get_children($children_terms, $max_depth, $depth);
			}
		}

		if($meta = $this->parse_meta()){
			$json	= array_merge($json, $meta);
		}

		return apply_filters('wpjam_term_json', $json, $this->id);
	}

	public function parse_meta(){
		$taxonomy	= $this->taxonomy;
		$tax_obj	= get_taxonomy($taxonomy);

		foreach(WPJAM_Term_Option::get_registereds() as $to_obj){
			if($to_obj->is_available_for_taxonomy($taxonomy) && !$to_obj->update_callback){
				foreach($to_obj->get_fields($this->id) as $meta_key => $field){
					if($show_in_rest = wpjam_parse_field_show_in_rest($field, $type, $default)){
						$args	= [
							'object_subtype'	=> $taxonomy,
							'single'			=> true,
							'type'				=> $type,
							'show_in_rest'		=> $show_in_rest
						];

						if(isset($default)){
							$args['default']	= $default;
						}

						register_meta('term', $meta_key, $args);
					}
				}
			}
		}

		if(!isset($tax_obj->rest_meta_fields)){
			$tax_obj->rest_meta_fields	= new WP_REST_Term_Meta_Fields($taxonomy);
		}

		return $tax_obj->rest_meta_fields->get_value($this->id, null);
	}

	private static $instances	= [];
	
	public static function get_instance($term=null){
		$term	= $term ?: get_queried_object();
		$term	= self::get_term($term);

		if(!$term || !($term instanceof WP_Term)){
			return new WP_Error('term_not_exists', '分类不存在');
		}

		if(!taxonomy_exists($term->taxonomy)){
			return new WP_Error('taxonomy_not_exists', '自定义分类不存在');
		}

		$term_id	= $term->term_id;

		if(!isset($instances[$term_id])){
			$instances[$term_id]	= new self($term_id);
		}

		return $instances[$term_id];
	}

	public static function get_meta_instance($taxonomy){
		if(!isset(self::$meta_instances[$taxonomy])){
			self::$meta_instances[$taxonomy]	= new WP_REST_Term_Meta_Fields($taxonomy);
		}

		return self::$meta_instances[$taxonomy];
	}

	/**
	* $max_depth = -1 means flatly display every element.
	* $max_depth = 0 means display all levels.
	* $max_depth > 0 specifies the number of display levels.
	*
	*/
	public static function get_terms($args, $max_depth=null){
		if(is_string($args) || wp_is_numeric_array($args)){
			$term_ids	= wp_parse_id_list($args);

			if(empty($term_ids)){
				return [];
			}

			$args		= ['orderby'=>'include', 'include'=>$term_ids];
			$max_depth	= $max_depth ?? -1;
		}else{
			if(empty($args['taxonomy']) 
				|| is_array($args['taxonomy']) 
				|| !get_taxonomy($args['taxonomy'])
			){
				return [];
			}

			if(is_null($max_depth)){
				$tax_obj	= get_taxonomy($args['taxonomy']);

				if($tax_obj->hierarchical){
					$max_depth	= $tax_obj->levels ?? 0;
				}else{
					$max_depth	= -1;
				}
			}
		}

		$args	= wp_parse_args($args, ['hide_empty'=>false]);

		if($max_depth != -1){
			if(isset($args['child_of'])){
				$parent	= $args['child_of'];
			}else{
				if($parent = wpjam_array_pull($args, 'parent')){
					$args['child_of']	= $parent;
				}
			}
		}

		$terms	= get_terms($args) ?: [];

		if(is_wp_error($terms) || empty($terms)){
			return $terms;
		}

		if($max_depth == -1){
			foreach($terms as &$term){
				$term	= self::get_instance($term)->parse_for_json();
			}
		}else{
			$top_level_terms	= [];
			$children_terms		= [];

			if($parent){
				$top_level_terms[] = get_term($parent);
			}

			foreach($terms as $term){
				if($term->parent == 0){
					$top_level_terms[] = $term;
				}elseif($max_depth != 1){
					$children_terms[$term->parent][] = $term;
				}
			}

			$terms	= $top_level_terms;

			foreach($terms as &$term){
				$term	= self::get_instance($term)->parse_for_json($children_terms, $max_depth, 0);
			}
		}

		return $terms;
	}

	public static function get($term){
		$data	= self::get_term($term, '', ARRAY_A);

		if($data && !is_wp_error($data)){
			$data['id']	= $data['term_id'];
		}

		return $data;
	}

	public static function insert($data){
		$taxonomy	= wpjam_array_pull($data, 'taxonomy');
		
		if(!$taxonomy){
			return new WP_Error('empty_taxonomy', '分类模式不能为空');
		}

		$name	= wpjam_array_pull($data, 'name');
		$args	= wp_array_slice_assoc($data, ['parent', 'slug', 'description', 'alias_of']);
		$term	= wp_insert_term(wp_slash($name), $taxonomy, wp_slash($args));

		if(is_wp_error($term)){
			return $term;
		}

		$term_id	= $term['term_id'];

		$meta_input	= wpjam_array_pull($data, 'meta_input');

		if(is_array($meta_input)){
			wpjam_update_metadata($term_id, $meta_input);
		}

		return $term_id;
	}

	public static function update($term_id, $data){
		$taxonomy	= wpjam_array_pull($data, 'taxonomy');

		if(!$taxonomy){
			$object	= self::get_instance($term_id);

			if(is_wp_error($object)){
				return $object;
			}

			$taxonomy	= $object->taxonomy;
		}

		if($args = wp_array_slice_assoc($data, ['name', 'parent', 'slug', 'description', 'alias_of'])){
			$term	= wp_update_term($term_id, $taxonomy, wp_slash($args));

			if(is_wp_error($term)){
				return $term;
			}
		}

		if($meta_input = wpjam_array_pull($data, 'meta_input')){
			if(is_arra($meta_input) && !wp_is_numeric_array($meta_input)){
				wpjam_update_metadata($term_id, $meta_input);
			}
		}

		return true;
	}

	public static function delete($term_id){
		$term	= get_term($term_id);

		if(is_wp_error($term) || empty($term)){
			return $term;
		}

		return wp_delete_term($term_id, $term->taxonomy);
	}

	public static function move($term_id, $data){
		$term	= get_term($term_id);

		$term_ids	= get_terms([
			'parent'	=> $term->parent,
			'taxonomy'	=> $term->taxonomy,
			'orderby'	=> 'name',
			'hide_empty'=> false,
			'fields'	=> 'ids'
		]);

		if(empty($term_ids) || !in_array($term_id, $term_ids)){
			return new WP_Error('key_not_exists', $term_id.'的值不存在');
		}

		$terms	= array_map(function($term_id){
			return ['id'=>$term_id, 'order'=>get_term_meta($term_id, 'order', true) ?: 0];
		}, $term_ids);

		$terms	= wp_list_sort($terms, 'order', 'DESC');
		$terms	= wp_list_pluck($terms, 'order', 'id');

		$next	= $data['next'] ?? false;
		$prev	= $data['prev'] ?? false;

		if(!$next && !$prev){
			return new WP_Error('invalid_move', '无效移动位置');
		}

		unset($terms[$term_id]);

		if($next){
			if(!isset($terms[$next])){
				return new WP_Error('key_not_exists', $next.'的值不存在');
			}

			$offset	= array_search($next, array_keys($terms));

			if($offset){
				$terms	= array_slice($terms, 0, $offset, true) +  [$term_id => 0] + array_slice($terms, $offset, null, true);
			}else{
				$terms	= [$term_id => 0] + $terms;
			}
		}else{
			if(!isset($terms[$prev])){
				return new WP_Error('key_not_exists', $prev.'的值不存在');
			}

			$offset	= array_search($prev, array_keys($terms));
			$offset ++;

			if($offset){
				$terms	= array_slice($terms, 0, $offset, true) +  [$term_id => 0] + array_slice($terms, $offset, null, true);
			}else{
				$terms	= [$term_id => 0] + $terms;
			}
		}

		$count	= count($terms);
		foreach ($terms as $term_id => $order) {
			if($order != $count){
				update_term_meta($term_id, 'order', $count);
			}

			$count--;
		}

		return true;
	}

	public static function get_meta($term_id, ...$args){
		return WPJAM_Meta::get_data('term', $term_id, ...$args);
	}

	public static function update_meta($term_id, ...$args){
		return WPJAM_Meta::update_data('term', $term_id, ...$args);
	}

	public static function update_metas($term_id, $data, $meta_keys=[]){
		return self::update_meta('term', $term_id, $data, $meta_keys);
	}

	public static function value_callback($meta_key, $term_id){
		return self::get_meta($term_id, $meta_key);
	}

	public static function get_by_ids($term_ids){
		return self::update_caches($term_ids);
	}

	public static function update_caches($term_ids, $args=[]){
		if($term_ids){
			$term_ids 	= array_filter($term_ids);
			$term_ids 	= array_unique($term_ids);
		}

		if(empty($term_ids)) {
			return [];
		}

		_prime_term_caches($term_ids, false);

		$tids	= [];

		if(function_exists('wp_cache_get_multiple')){
			$cache_values	= wp_cache_get_multiple($term_ids, 'terms');

			foreach($term_ids as $term_id){
				if(empty($cache_values[$term_id])){
					wp_cache_add($term_id, false, 'terms', 10);	// 防止大量 SQL 查询。
				}else{
					$tids[]	= $term_id;
				}
			}
		}else{
			$cache_values	= [];

			foreach ($term_ids as $term_id) {
				$cache	= wp_cache_get($term_id, 'terms');

				if($cache !== false){
					$cache_values[$term_id]	= $cache;

					$tids[]	= $term_id;
				}
			}
		}

		if(!empty($args['update_meta_cache'])){
			update_termmeta_cache($tids);
		}else{
			$lazyloader	= wp_metadata_lazyloader();
			$lazyloader->queue_objects('term', $tids);
		}

		return $cache_values;
	}

	public static function get_term($term, $taxonomy='', $output=OBJECT, $filter='raw'){
		if($term && is_numeric($term)){
			$found	= false;
			$cache	= wp_cache_get($term, 'terms', false, $found);

			if($found){
				if(is_wp_error($cache)){
					return $cache;
				}elseif(!$cache){
					return null;
				}
			}else{
				$_term	= WP_Term::get_instance($term, $taxonomy);

				if(is_wp_error($_term)){
					return $_term;
				}elseif(!$_term){	// 不存在情况下的缓存优化，防止重复 SQL 查询。
					wp_cache_add($term, false, 'terms', 10);
					return null;
				}
			}
		}

		return get_term($term, $taxonomy, $output, $filter);
	}

	public static function get_related_object_ids($tt_ids, $number, $page=1){
		$id_str		= implode(',', array_map('intval', $tt_ids));
		$cache_key	= 'related_object_ids:'.$id_str.':'.$page.':'.$number;
		$object_ids	= wp_cache_get($cache_key, 'terms');

		if($object_ids === false){
			$object_ids	= $GLOBALS['wpdb']->get_col('SELECT object_id, count(object_id) as cnt FROM '.$GLOBALS['wpdb']->term_relationships.' WHERE term_taxonomy_id IN ('.$id_str.') GROUP BY object_id ORDER BY cnt DESC LIMIT '.(($page-1) * $number).', '.$number);

			wp_cache_set($cache_key, $object_ids, 'terms', DAY_IN_SECONDS);
		}

		return $object_ids;
	}

	public static function get_id_field($taxonomy, $args=[]){
		if($tax_obj	= get_taxonomy($taxonomy)){
			$title	= $tax_obj->label;

			if($tax_obj->hierarchical 
				&& (!is_admin() 
					|| (is_admin() && wp_count_terms(['taxonomy'=>$taxonomy]) <= 30)
				)
			){
				$levels		= $tax_obj->levels ?? 0;
				$terms		= self::get_terms(['taxonomy'=>$taxonomy, 'hide_empty'=>0], $levels);
				$options	= $terms ? array_column(wpjam_list_flatten($terms), 'name', 'id') : [];

				$option_all	= wpjam_array_pull($args, 'option_all');

				if($option_all){
					$option_all	= $option_all === true ? '所有'.$title :  $args['option_all'];
					$options	= [''=>$option_all]+$options;
				}

				return wp_parse_args($args, [
					'title'		=> $title,
					'type'		=> 'select',
					'options'	=> $options
				]);
			}else{
				return wp_parse_args($args, [
					'title'			=> $title,
					'type'			=> 'text',
					'class'			=> 'all-options',
					'data_type'		=> 'taxonomy',
					'taxonomy'		=> $taxonomy,
					'placeholder'	=> '请输入'.$title.'ID或者输入关键字筛选'
				]);
			}
		}
		
		return [];	
	}
}

class WPJAM_Taxonomy{
	use WPJAM_Register_Trait;

	public function parse_args(){
		$args	= $this->args;

		if(isset($args['args'])){
			$object_type	= $args['object_type'] ?? [];

			$args	= array_merge($args['args'], ['object_type'=>$object_type]);
		}

		$args = wp_parse_args($args, [
			'permastruct'		=> null,
			'rewrite'			=> true,
			'show_ui'			=> true,
			'show_in_nav_menus'	=> false,
			'show_admin_column'	=> true,
			'hierarchical'		=> true
		]);

		if($args['permastruct'] && empty($args['rewrite'])){
			$args['rewrite']	= true;
		}

		if($args['rewrite']){
			$args['rewrite']	= is_array($args['rewrite']) ? $args['rewrite'] : [];
			$args['rewrite']	= wp_parse_args($args['rewrite'], ['with_front'=>false, 'feed'=>false, 'hierarchical'=>false]);
		}

		return $args;
	}

	public static function filter_register_args($args, $name){
		$args = wp_parse_args($args, [
			'supports'		=> ['slug', 'description', 'parent'],
			'permastruct'	=> null,
			'levels'		=> null,
			'sortable'		=> null,
			'filterable'	=> null,
		]);

		if($args['permastruct']){
			if(strpos($args['permastruct'], '%term_id%') || strpos($args['permastruct'], '%'.$name.'_id%')){
				$args['permastruct']	= str_replace('%term_id%', '%'.$name.'_id%', $args['permastruct']);
				$args['supports']		= array_diff($args['supports'], ['slug']);
				$args['query_var']		= $args['query_var'] ?? false;
			}
		}

		return $args;
	}

	public static function on_registered($name, $object_type, $args){
		if(!empty($args['permastruct'])){
			if(strpos($args['permastruct'], '%'.$name.'_id%')){
				$GLOBALS['wp_rewrite']->extra_permastructs[$name]['struct']	= $args['permastruct'];

				add_rewrite_tag('%'.$name.'_id%', '([^/]+)', 'taxonomy='.$name.'&term_id=');
				remove_rewrite_tag('%'.$name.'%');
			}elseif(strpos($args['permastruct'], '%'.$args['rewrite']['slug'].'%')){
				$GLOBALS['wp_rewrite']->extra_permastructs[$name]['struct']	= $args['permastruct'];
			}
		}

		$registered_callback	= $args['registered_callback'] ?? '';

		if($registered_callback && is_callable($registered_callback)){
			call_user_func($registered_callback, $name, $object_type, $args);
		}
	}

	public static function filter_labels($labels){
		$taxonomy	= str_replace('taxonomy_labels_', '', current_filter());
		$args		= self::get($taxonomy)->to_array();
		$_labels	= $args['labels'] ?? [];

		$labels		= (array)$labels;
		$name		= $labels['name'];

		if(empty($args['hierarchical'])){
			$search		= ['标签', 'Tag', 'tag'];
			$replace	= [$name, ucfirst($name), $name];
		}else{
			$search		= ['目录', '分类', 'categories', 'Categories', 'Category'];
			$replace	= ['', $name, $name, $name.'s', ucfirst($name).'s', ucfirst($name)];
		}

		foreach ($labels as $key => &$label) {
			if($label && empty($_labels[$key]) && $label != $name){
				$label	= str_replace($search, $replace, $label);
			}
		}

		return $labels;
	}

	public static function filter_link($term_link, $term){
		if(array_search('%'.$term->taxonomy.'_id%', $GLOBALS['wp_rewrite']->rewritecode, true)){
			$term_link	= str_replace('%'.$term->taxonomy.'_id%', $term->term_id, $term_link);
		}

		return $term_link;
	}

	public static function filter_data_type_field_value($value, $field){
		if($field['data_type'] == 'taxonomy'){
			if($field['type'] == 'mu-text'){
				foreach($value as &$item){
					$item	= self::filter_data_type_field_value($item, array_merge($field, ['type'=>'text']));
				}

				$value	= array_filter($value);
			}else{
				if(!is_numeric($value)){
					if($result	= term_exists($value, $field['taxonomy'])){
						return is_array($result) ? $result['term_id'] : $result;
					}elseif(!empty($field['creatable'])){
						return WPJAM_Term::insert(['name'=>$value, 'taxonomy'=>$field['taxonomy']]); 
					}else{
						return null;
					}
				}else{
					return get_term($value, $field['taxonomy']) ? (int)$value : null;
				}
			}
		}

		return $value;
	}
}

class WPJAM_Term_Option{
	use WPJAM_Register_Trait;

	public function parse_args(){
		$args	= $this->args;

		if(empty($args['value_callback']) || !is_callable($args['value_callback'])){
			$args['value_callback']	= ['WPJAM_Term', 'value_callback'];
		}

		return $args;
	}

	public function is_available_for_taxonomy($taxonomy){
		return is_callable($this->args) || is_null($this->taxonomy) || in_array($taxonomy, (array)$this->taxonomy);
	}

	public function get_fields($term_id=null){
		if(is_callable($this->args)){
			return call_user_func($this->args, $term_id, $this->name);
		}elseif(!empty($this->fields)){
			if(is_callable($this->fields)){
				return call_user_func($this->fields, $term_id, $this->name);
			}else{
				return $this->fields;
			}
		}else{
			$field	= wpjam_array_except($this->args, 'taxonomy');

			return [$this->name => $field];
		}
	}
}