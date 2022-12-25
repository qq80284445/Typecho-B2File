<?php
/**
 * Backblaze-B2上传文件插件for typecho,代码核心https://github.com/gliterd/backblaze-b2/
 *
 * @package Backblaze-B2 File
 * @author Tea
 * @version 1.0.3
 * @link http://momog.cn/
 * @date 2020-12-25
 */
require __DIR__ . '/vendor/autoload.php';
use BackblazeB2\Client;
use BackblazeB2\Bucket;

class B2File_Plugin implements Typecho_Plugin_Interface
{
    // 激活插件
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Upload')->uploadHandle = array('B2File_Plugin', 'uploadHandle');
        Typecho_Plugin::factory('Widget_Upload')->modifyHandle = array('B2File_Plugin', 'modifyHandle');
        Typecho_Plugin::factory('Widget_Upload')->deleteHandle = array('B2File_Plugin', 'deleteHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentHandle = array('B2File_Plugin', 'attachmentHandle');
        return _t('插件已经激活，需先配置B2的信息！');
    }


    // 禁用插件
    public static function deactivate()
    {
        return _t('插件已被禁用');
    }


    // 插件配置面板
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $bucket = new Typecho_Widget_Helper_Form_Element_Text('bucket', null, null, _t('空间名称：'));
        $form->addInput($bucket->addRule('required', _t('“空间名称”不能为空！')));

        $accesskey = new Typecho_Widget_Helper_Form_Element_Text('accesskey', null, null, _t('keyID：'));
        $form->addInput($accesskey->addRule('required', _t('keyID 不能为空！')));

        $sercetkey = new Typecho_Widget_Helper_Form_Element_Text('sercetkey', null, null, _t('applicationKey:'), _t('<a href="https://assets.momog.cn/images/b2getkey.png" target="_blank">如何获取keyid及key</a>'));
        $form->addInput($sercetkey->addRule('required', _t('applicationKey 不能为空！')));

        $domain = new Typecho_Widget_Helper_Form_Element_Text('domain', null, 'http://', _t('绑定域名：'), _t('以 http:// 开头，结尾不要加 / ！可以是<a href="https://assets.momog.cn/images/b2geturl.png" target="_blank">空间自带域名</a>，例如:https://f004.backblazeb2.com/file/桶名 <br /> 也可以是<a href="https://www.momog.cn/archives/b2andcloudflare.html" target="_blank">cloudflare指定cname</a>'));
        $form->addInput($domain->addRule('required', _t('请填写空间绑定的域名！'))->addRule('url', _t('您输入的域名格式错误！')));

        $savepath = new Typecho_Widget_Helper_Form_Element_Text('savepath', null, 'blog/typecho/{year}{month}{day}/', _t('保存路径前缀'), _t('请填写保存路径格前缀，以便数据管理和迁移<br />支持<mark>{year}{month}{day}</mark>,分别是生成年/月/日,<br />如 <mark>images/{year}{month}{day}/</mark> ,<mark>images/{year}/{month}/{day}/</mark> 自由发挥或修改源码'));
        $form->addInput($savepath);

        $imgstyle = new Typecho_Widget_Helper_Form_Element_Text('imgstyle', null, '0', _t('图片是否修改成随机名称？：'), _t('1 随机名称 ,其它值 不改名'));
        $form->addInput($imgstyle);
    }


    // 个人用户配置面板
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }


    // 获得插件配置信息
    public static function getConfig()
    {
        return Typecho_Widget::widget('Widget_Options')->plugin('B2File');
    }


    // 删除文件
    public static function deleteFile($filepath)
    {
        // // 获取插件配置
        $option = self::getConfig();
        $b2options = ['auth_timeout_seconds' => 100];
        
        $b2client = new Client($option->accesskey, $option->sercetkey, $b2options);       
       
        $fileDelete = $b2client->deleteFile([
            
             'BucketName' => $option->bucket,
             'FileName' => $filepath
        ]);
        return true;
    }


    // 上传文件
    public static function uploadFile($file, $content = null)
    {

        // 获取上传文件
        if (empty($file['name'])) return false;

        // 校验扩展名
        $part = explode('.', $file['name']);
        $ext = (($length = count($part)) > 1) ? strtolower($part[$length-1]) : '';
        if (!Widget_Upload::checkFileType($ext)) return false;

        // 获取插件配置
        $option = self::getConfig();
        $date = new Typecho_Date(Typecho_Widget::widget('Widget_Options')->gmtTime);

        // 保存位置
        $savepath = preg_replace(array('/\{year\}/', '/\{month\}/', '/\{day\}/'), array($date->year, $date->month, $date->day), $option->savepath);
        $savename = $file['name'] ;
        @$imgstyle = $option->imgstyle * 1;
        if(isset($imgstyle) && $imgstyle == 1){
        $savename = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        }
        // if (isset($content))
        // {
        //     $savename = $content['attachment']->path;
        //     self::deleteFile($savename);
        // } 

        // 上传文件 {year}/{month}/{day}/{md5}.{extName}
        $filename = $file['tmp_name']; //var_dump($file);exit;
        if (!isset($filename)) return false;

        $b2options = ['auth_timeout_seconds' => 100];
        
        $b2client = new Client($option->accesskey, $option->sercetkey, $b2options);

        $b2ret = $b2client->upload([
            'BucketName' => $option->bucket,
            'FileName' => $savepath . $savename,
            'Body' => fopen($filename, 'r')
        
            // The file content can also be provided via a resource.
            // 'Body' => fopen('/path/to/input', 'r')
        ]);
        
        $b2result = $b2ret->name;
        
        if ($b2result)
        {
            return array
            (
                'name'  =>  $savename,
                'path'  =>  $savepath . $savename,
                'size'  =>  $file['size'],
                'type'  =>  $ext,
                'mime'  =>  Typecho_Common::mimeContentType($filename)
            );
        }
        else return false;
    }


    // 上传文件处理函数
    public static function uploadHandle($file)
    {
        return self::uploadFile($file);
    }


    // 修改文件处理函数
    public static function modifyHandle($content, $file)
    {
        return self::uploadFile($file, $content);
    }


    // 删除文件
    public static function deleteHandle(array $content)
    {
        self::deleteFile($content['attachment']->path);
    }


    // 获取实际文件绝对访问路径
    public static function attachmentHandle(array $content)
    {
        $option = self::getConfig();
        return Typecho_Common::url($content['attachment']->path, $option->domain);
    }
}
