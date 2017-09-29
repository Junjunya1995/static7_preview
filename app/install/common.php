<?php

// +----------------------------------------------------------------------
// | OneThink [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2013 http://www.onethink.cn All rights reserved.
// +----------------------------------------------------------------------
// | Author: 麦当苗儿 <zuojiazi@vip.qq.com> <http://www.zjzit.cn>
// +----------------------------------------------------------------------
// 检测环境是否支持可写
//define('IS_WRITE', true);

use think\facade\{
    Request,Session,Config,Env
};

/**
 * 系统环境检测
 * @return array 系统环境数据
 */
function check_env() {
    $items = [
        'os' => ['操作系统', '不限制', '类Unix', PHP_OS, 'success'],
        'php' => ['PHP版本', '7.1.2', '5.3+', PHP_VERSION, 'success'],
        'upload' => ['附件上传', '不限制', '2M+', '未知', 'success'],
        'gd' => ['GD库', '2.0', '2.0+', '未知', 'success'],
        'disk' => ['磁盘空间', '10M', '不限制', '未知', 'success'],
    ];

    //PHP环境检测
    if ($items['php'][3] < $items['php'][1]) {
        $items['php'][4] = 'error';
        Session::set('error', true, 'install');
    }
    //附件上传检测
    if (@ini_get('file_uploads')) {
        $items['upload'][3] = ini_get('upload_max_filesize');
    }
    //GD库检测
    $tmp = function_exists('gd_info') ? gd_info() : [];
    if (empty($tmp['GD Version'])) {
        $items['gd'][3] = '未安装';
        $items['gd'][4] = 'error';
        Session::set('error', true, 'install');
    } else {
        $items['gd'][3] = $tmp['GD Version'];
    }
    unset($tmp);
    //磁盘空间检测
    if (function_exists('disk_free_space')) {
        $items['disk'][3] = floor(disk_free_space(realpath('./')) / (1024 * 1024)) . 'M';
    }
    return $items;
}

/**
 * 目录，文件读写检测
 * @return array 检测数据
 */
function check_dirfile() {
    $items = [
        ['dir', '可写', 'success', DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'upload'.DIRECTORY_SEPARATOR.'default'.DIRECTORY_SEPARATOR.'file'],
        ['dir', '可写', 'success', DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'upload'.DIRECTORY_SEPARATOR.'default'.DIRECTORY_SEPARATOR.'picture'],
        ['dir', '可写', 'success', DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'upload'.DIRECTORY_SEPARATOR.'head_portrait'],
        ['dir', '可写', 'success', DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'install'.DIRECTORY_SEPARATOR.'data'],
        ['dir', '可写', 'success', DIRECTORY_SEPARATOR.'runtime'],
        ['file', '可写', 'success', DIRECTORY_SEPARATOR.'app'],
    ];

    foreach ($items as &$val) {
        $item = realpath('../') . $val[3];
        if ('dir' == $val[0]) {
            if (!is_writable($item)) {
                if (is_dir($item)) {
                    $val[1] = '可读';
                    $val[2] = 'error';
                    Session::set('error', true, 'install');
                } else {
                    $val[1] = '不存在';
                    $val[2] = 'error';
                    Session::set('error', true, 'install');
                }
            }
        } else {
            if (file_exists($item)) {
                if (!is_writable($item)) {
                    $val[1] = '不可写';
                    $val[2] = 'error';
                    Session::set('error', true, 'install');
                }
            } else {
                if (!is_writable(dirname($item))) {
                    $val[1] = '不存在';
                    $val[2] = 'error';
                    Session::set('error', true, 'install');
                }
            }
        }
    }

    return $items;
}

/**
 * 函数检测
 * @return array 检测数据
 */
function check_func() {
    $items = [
        ['pdo', '支持', 'success', '类'],
        ['DomDocument', '支持', 'success', '类'],
        ['pdo_mysql', '支持', 'success', '模块'],
        ['curl', '支持', 'success', '模块'],
        ['openssl', '支持', 'success', '模块'],
        ['file_get_contents', '支持', 'success', '函数'],
        ['mb_strlen', '支持', 'success', '函数'],
    ];

    foreach ($items as &$val) {
        if (
            ('类' == $val[3] && !class_exists($val[0])) ||
            ('模块' == $val[3] && !extension_loaded($val[0])) ||
            ('函数' == $val[3] && !function_exists($val[0]))
        ) {
            $val[1] = '不支持';
            $val[2] = 'red';
            Session::set('error', true, 'install');
        }
    }

    return $items;
}

/**
 * 写入配置文件
 * @param  array $config 配置信息
 * @param string $auth 密钥信息
 * @return string
 */
function write_config($config, $auth)
{
    if (is_array($config)) {
        //读取配置内容
        $conf = file_get_contents(Env::get('module_path') . 'data/database.tpl');
        //替换配置项
        foreach ($config as $name => $value) {
            $conf = str_replace("[{$name}]", $value, $conf);
        }
        //读取密钥内容
        $keyInfo = file_get_contents(Env::get('module_path') . 'data/key.tpl');
        $key     = str_replace('[AUTH_KEY]', $auth, $keyInfo);
        //写入应用配置文件
        if (file_put_contents(Env::get('config_path') . 'database.php', $conf) &&
            file_put_contents(Env::get('config_path') . "key.php", $key)) {
            show_msg('配置文件写入成功!');
        } else {
            show_msg('配置文件写入失败！', 'error');
            Session::set('error', true, 'install');
        }
        return true;
    }
}

/**
 * 创建数据表
 * @param  resource $db 数据库连接资源
 * @param string $prefix
 */
function create_tables($db, $prefix = '') {
    //读取SQL文件
    $sql = file_get_contents(Env::get('module_path') . '/data/install.sql');
    $sql = explode(";\n", str_replace("\r", "\n", $sql));
    //替换表前缀
    $orginal = Config::get('config.orginal_table_prefix');
    ($orginal==$prefix) ? true : $sql = str_replace(" `{$orginal}", " `{$prefix}", $sql);
    //开始安装
    show_msg('开始安装数据库...');
    foreach ($sql as $value) {
        $value = trim($value);
        if (empty($value)) {
            continue;
        }
        if (substr($value, 0, 12) == 'CREATE TABLE') {
            $name = preg_replace("/^CREATE TABLE `(\w+)` .*/s", "\\1", $value);
            $msg = "创建数据表{$name}";
            if (false !== $db->execute($value)) {
                show_msg($msg . '...成功!');
            } else {
                show_msg($msg . '...失败！', 'error');
                Session::set('error', true, 'install');
            }
        } else {
            $db->execute($value);
        }
    }
}

function register_administrator($db, $prefix, $admin, $auth) {
    show_msg('开始注册创始人帐号...');
    $sql = "INSERT INTO `[PREFIX]ucenter_member` VALUES " .
        "('1', '[NAME]', '[PASS]', '[EMAIL]', '', '[TIME]', '[IP]', 0, 0, '[TIME]', '1')";

    $password = ucenter_md5($admin['password'], $auth);
    $sql = str_replace(
        ['[PREFIX]', '[NAME]', '[PASS]', '[EMAIL]', '[TIME]', '[IP]'],
        [$prefix, $admin['username'], $password, $admin['email'], Request::time(), Request::ip(1)], $sql);
    //执行sql
    $db->execute($sql);

    $sql = "INSERT INTO `[PREFIX]member` VALUES " .
        "('1', '[NAME]', '0', '0', '', '0', '1', '0', '[TIME]', '0', '[TIME]', '1','0');";
    $sql = str_replace(['[PREFIX]', '[NAME]', '[TIME]'], [$prefix, $admin['username'], Request::time()], $sql);
    $db->execute($sql);
    show_msg('创始人帐号注册完成！');
}

/**
 * 更新数据表
 * @param  resource $db 数据库连接资源
 * @param string $prefix
 * @author lyq <605415184@qq.com>
 */
function update_tables($db, $prefix = '') {
    //读取SQL文件
    $sql = file_get_contents(APP_PATH . 'install/data/update.sql');
    $sql = str_replace("\r", "\n", $sql);
    $sql = explode(";\n", $sql);

    //替换表前缀
    $sql = str_replace(" `tp5_", " `{$prefix}", $sql);

    //开始安装
    show_msg('开始升级数据库...');
    foreach ($sql as $value) {
        $value = trim($value);
        if (empty($value)) {
            continue;
        }
        if (substr($value, 0, 12) == 'CREATE TABLE') {
            $name = preg_replace("/^CREATE TABLE `(\w+)` .*/s", "\\1", $value);
            $msg = "创建数据表{$name}";
            if (false !== $db->execute($value)) {
                show_msg($msg . '...成功!');
            } else {
                show_msg($msg . '...失败！', 'error');
                Session::set('error', true, 'install');
            }
        } else {
            if (substr($value, 0, 8) == 'UPDATE `') {
                $name = preg_replace("/^UPDATE `(\w+)` .*/s", "\\1", $value);
                $msg = "更新数据表{$name}";
            } else if (substr($value, 0, 11) == 'ALTER TABLE') {
                $name = preg_replace("/^ALTER TABLE `(\w+)` .*/s", "\\1", $value);
                $msg = "修改数据表{$name}";
            } else if (substr($value, 0, 11) == 'INSERT INTO') {
                $name = preg_replace("/^INSERT INTO `(\w+)` .*/s", "\\1", $value);
                $msg = "写入数据表{$name}";
            }
            if (($db->execute($value)) !== false) {
                show_msg($msg . '...成功!');
            } else {
                show_msg($msg . '...失败！', 'error');
                Session::set('error', true, 'install');
            }
        }
    }
}

/**
 * 及时显示提示信息
 * @param  string $msg 提示信息
 * @param string $class
 * @param string $jump
 */
function show_msg($msg, $class = '',$jump='') {
    echo "<script type=\"text/javascript\">showmsg(\"{$msg}\", \"{$class}\",\"{$jump}\")</script>";
    flush();
    ob_flush();
}

/**
 * 生成系统AUTH_KEY
 * @author 麦当苗儿 <zuojiazi@vip.qq.com>
 */
function build_auth_key() {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $chars .= '`~!@#$%^&*()_+-=[]{};:"|,.<>/?';
    $chars = str_shuffle($chars);
    return substr($chars, 0, 40);
}