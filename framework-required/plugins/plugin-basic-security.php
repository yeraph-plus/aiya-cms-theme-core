<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('AYA_Theme_Setup')) exit;

/*
 * Name: WP 安全性优化插件
 * Version: 1.0.0
 * Author: AIYA-CMS
 * Author URI: https://www.yeraph.com
 */

class AYA_Plugin_Security extends AYA_Theme_Setup
{
    public $security_options;

    public function __construct($args)
    {
        $this->security_options = $args;
    }

    public function __destruct()
    {
        add_action('admin_init', array($this, 'aya_theme_admin_backend_verify'));

        add_filter('allow_password_reset', array($this, 'aya_theme_disallow_password_reset'), 10, 2);

        add_filter('pre_user_name', array($this, 'aya_theme_prevent_disable_admin_user'));
        add_filter('pre_user_login', array($this, 'aya_theme_prevent_clean_admin_user'));

        add_filter('authenticate', array($this, 'aya_theme_logged_disable_admin_user'), 30, 3);
        add_filter('authenticate', array($this, 'aya_theme_logged_scope_limit_verify'), 10, 3);

        add_action('wp_login_failed', array($this, 'aya_theme_limit_login_attempts'), 10, 1);
        add_action('wp_login', array($this, 'aya_theme_reset_login_attempts'), 10, 1);

        add_filter('shake_error_codes', array($this, 'aya_theme_logged_shake_error_codes'));

        add_filter('robots_txt', array($this, 'aya_theme_custom_robots_txt'), 10, 2);

        add_action('init', array($this, 'aya_theme_init_rewind_url_reject'));
        add_action('init', array($this, 'aya_theme_init_user_agent_reject'));
    }
    //限制后台访问
    public function aya_theme_admin_backend_verify()
    {

        if (!is_admin()) return;

        //DEBUG：跳过AJAX请求防止影响第三方登录方式
        if ($_SERVER['PHP_SELF'] == '/wp-admin/admin-ajax.php') return;

        $options = $this->security_options;

        if (empty($options['admin_backend_verify'])) $verify = true;

        //检查登录用户权限
        switch ($options['admin_backend_verify']) {
                //case 'administrator':
                //$verify = current_user_can('manage_options');
                //break;
            case 'editor':
                $verify = (current_user_can('publish_pages')  && !current_user_can('manage_options'));
                break;
            case 'author':
                $verify = (current_user_can('publish_posts')  &&  !current_user_can('publish_pages'));
                break;
            case 'contributor':
                $verify = (current_user_can('edit_posts')  &&  !current_user_can('publish_posts'));
                break;
            case 'subscriber':
                $verify = (current_user_can('read')  && !current_user_can('edit_posts'));
                break;
            default:
                $verify = true;
                break;
        }
        //重定向
        if ($verify == false) {
            wp_redirect('/');
        }
    }
    //限制特定权限用户修改密码
    public function aya_theme_disallow_password_reset($allow, $user)
    {
        if (!$allow) return false;

        $options = $this->security_options;

        if ($options['admin_disallow_password_reset'] == true) {

            //验证用户角色
            $user = get_userdata($user);

            if (in_array('administrator', $user->roles)) {
                return false;
            }
        }

        return true;
    }
    //禁用 admin 用户名注册
    public function aya_theme_prevent_disable_admin_user($user)
    {
        $options = $this->security_options;

        if ($options['logged_sanitize_user_enable'] == true) {
            //排除用户名
            if ($options['logged_prevent_user_name'] != '') {
                //重建数组
                $disable_user_map = explode(',', $options['logged_prevent_user_name']);
                $disable_user_map = array_map('trim', $disable_user_map);
            }

            $disable_user_map = (empty($disable_user_map)) ? array() : $disable_user_map;

            //验证用户名是否出现在数组中
            if (in_array($user, $disable_user_map)) {
                return false;
            }
        }
        return $user;
    }
    //清理用户名中包含的 admin 字符串
    public function aya_theme_prevent_clean_admin_user($username)
    {
        $options = $this->security_options;

        if ($options['logged_sanitize_user_enable'] == true) {
            //排除用户名
            if ($options['logged_register_user_name'] != '') {
                //重建数组
                $disable_user_map = explode(',', $options['logged_register_user_name']);
                $disable_user_map = array_map('trim', $disable_user_map);
            }

            $disable_user_map = (empty($disable_user_map)) ? array() : $disable_user_map;

            //移除用户名中的指定字符
            $cleaned_username = str_ireplace($disable_user_map, '', $username);

            //返回清理后的用户名
            return $cleaned_username;
        }
        return $username;
    }
    //禁用 admin 用户名进行身份验证
    public function aya_theme_logged_disable_admin_user($user, $username, $password)
    {
        $options = $this->security_options;

        if ($options['logged_sanitize_user_enable'] == true) {
            //排除用户名
            if ($options['logged_prevent_user_name'] != '') {
                //重建数组
                $disable_user_map = explode(',', $options['logged_prevent_user_name']);
                $disable_user_map = array_map('trim', $disable_user_map);
            }

            $disable_user_map = (empty($disable_user_map)) ? array() : $disable_user_map;

            //验证用户名是否出现在数组中
            if (in_array($username, $disable_user_map)) {

                $err_id = 'login_username_not_access';
                $err_msg = __('Sorry, you cannot log in with this username.');

                $user = new WP_Error($err_id, $err_msg);
            }
        }
        return $user;
    }
    //设置允许的最大登录尝试次数
    public function aya_theme_limit_login_attempts($username)
    {
        $options = $this->security_options;

        //当前用户的登录尝试次数
        $attempts = get_transient('login_attempts_' . $username);
        $attempts = $attempts ?: 0;

        //获取限制时间
        $defend_time = $options['logged_scope_limit_times'];
        $defend_time = (is_numeric($defend_time)) ? (intval($defend_time)) : 15;

        //增加登录尝试计数
        $attempts++;
        //限制时间窗口
        set_transient('login_attempts_' . $username, $attempts, MINUTE_IN_SECONDS * $defend_time);
    }
    //登录成功后重置登录尝试次数
    public function aya_theme_reset_login_attempts($user)
    {
        //重置登录尝试次数
        delete_transient('login_attempts_' . $user->user_login);
    }
    //验证登录次数
    public function aya_theme_logged_scope_limit_verify($user, $username, $password)
    {
        $options = $this->security_options;

        if ($options['logged_scope_limit_enable'] == true) {
            //允许最大登录尝试次数
            $max_attempts = 5;

            //当前用户的登录尝试次数
            $attempts = get_transient('login_attempts_' . $username);

            //获取限制时间
            $defend_time = $options['logged_scope_limit_times'];
            $defend_time = (is_numeric($defend_time)) ? (intval($defend_time)) : 15;

            //进行攻防时间
            if ($attempts >= $max_attempts) {
                remove_filter('authenticate', 'wp_authenticate_username_password', 20, 3);
                remove_filter('authenticate', 'wp_authenticate_email_password', 20, 3);

                $err_id = 'login_too_many_retries';
                $err_msg = __('You have tried multiple failed logins, please try again in ' . $defend_time . ' minutes!');

                return new WP_Error($err_id, $err_msg);
            }
        }
        return $user;
    }
    //创建登录框动态
    public function aya_theme_logged_shake_error_codes($error_codes)
    {
        $error_codes[] = 'login_too_many_retries';
        $error_codes[] = 'login_username_not_access';

        return $error_codes;
    }

    //WAF功能

    //robots.txt
    public function aya_theme_custom_robots_txt($output, $public)
    {
        $options = $this->security_options;

        if ($options['robots_custom_switch'] && $options['robots_custom_txt'] != '') {
            //替换为自定义输出
            $output = esc_attr(wp_strip_all_tags($options['robots_custom_txt']));
        }
        return $output;
    }
    //验证访问URL参数
    public function aya_theme_init_rewind_url_reject()
    {
        $options = $this->security_options;
        //获取设置
        if ($options['waf_reject_argument_switch'] == true) {
            //获取屏蔽参数列表
            if ($options['waf_reject_argument_list'] != '') {
                //重建数组
                $key_list = explode(',', $options['waf_reject_argument_list']);
                $key_list = array_map('trim', $key_list);
            }

            $key_list = (empty($key_list)) ? array() : $key_list;

            if (count($key_list) > 0) {
                //循环
                foreach ($key_list as $key) {
                    //获取请求参数
                    if (isset($_GET[$key])) {
                        //返回报错
                        return self::aya_theme_error_rewind_url_reject();
                    }
                }
            }
        }
    }
    //验证访问UA
    public function aya_theme_init_user_agent_reject()
    {
        $options = $this->security_options;

        //获取设置
        if ($options['waf_reject_useragent_switch'] == true) {
            //获取UA信息
            $user_agent = $_SERVER['HTTP_USER_AGENT'];
            //禁止空UA
            if ($options['waf_reject_useragent_empty'] == true) {
                //不存在则返回报错
                if (!$user_agent) return self::aya_theme_error_rewind_ua_reject();
            }
            //UA信息转为小写
            $user_agent = strtolower($user_agent);
            //获取UA黑名单
            if ($options['waf_reject_useragent_list'] != '') {
                //重建数组
                $ua_black_list = explode(',', $options['waf_reject_useragent_list']);
                $ua_black_list = array_map('trim', $ua_black_list);
            }

            $ua_black_list = (empty($ua_black_list)) ? array() : $ua_black_list;

            if (count($ua_black_list) > 0) {
                //循环
                foreach ($ua_black_list as $this_ua) {
                    //判断是否是数组中存在的UA
                    if (strpos($user_agent, strtolower($this_ua)) !== false) {
                        //返回报错
                        return self::aya_theme_error_rewind_ua_reject();
                    }
                }
            }
        }
    }
    //返回参数非法报错
    public function aya_theme_error_rewind_url_reject()
    {
        $message = __('The URL carries unlawful args.');
        $title = __('Access was denied.');
        $args = array(
            'response' => 403,
            'link_url' => home_url('/'),
            'link_text' => __('Return Homepage'),
            'back_link' => false,
        );

        wp_die($message, $title, $args);

        exit;
    }
    //返回UA非法报错
    public function aya_theme_error_rewind_ua_reject()
    {
        $message = __('The current browser userAgent is disabled by the site administrator.');
        $title = __('Access was denied.');
        $args = array(
            'response' => 403,
            'back_link' => false,
        );

        wp_die($message, $title, $args);

        exit;
    }
}
