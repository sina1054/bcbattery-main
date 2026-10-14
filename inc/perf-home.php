<?php
// Remove duplicate Elementor Swiper on home (Woodmart ships its own)
function bc_drop_dup_swiper(){ if(!is_front_page()) return; foreach(['alfa_swiper_js'] as $h){ wp_dequeue_script($h); } }
add_action('wp_enqueue_scripts','bc_drop_dup_swiper',9999);
add_action('wp_print_scripts','bc_drop_dup_swiper',1);
add_action('wp_print_footer_scripts','bc_drop_dup_swiper',1);
add_filter('script_loader_tag',function($t,$h){ return (is_front_page() && $h==='alfa_swiper_js') ? '' : $t; },10,2);

// Preload body fonts on home + product category archives only
function bc_preload_fonts(){ if(!(is_front_page()||is_product_category())) return; $b=get_stylesheet_directory_uri().'/font/yekanbakhfa/woff2/'; foreach(['Regular','SemiBold','Bold'] as $w){ echo '<link rel="preload" as="font" type="font/woff2" href="'.esc_url($b.'YekanBakhFaNum-'.$w.'.woff2').'" crossorigin>'."\n"; } }
add_action('wp_head','bc_preload_fonts',1);
