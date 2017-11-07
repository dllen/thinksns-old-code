<?php

require_once __DIR__ . '/aliyun-oss-php-sdk/autoload.php';

use OSS\OssClient;
use OSS\Core\OssException;

class AliOss
{
    public function version()
    {
        return '1.0.1';
    }

    private $bucketname;
    private $username;
    private $password;
    private $api_domain = 'oss-cn-beijing.aliyuncs.com';
    private $tmp_infos;
    public $timeout = 300;
    public $debug = false;
    private $content_md5 = null;
    private $file_secret = null;
    private $oss_client = null;

    /**
     * 初始化 OSS 存储接口.
     *
     * @param $bucketname 空间名称
     * @param $username 操作员名称
     * @param $password 密码
     * @param $api_domain 调用end_point
     * return UpYun object
     */
    public function __construct($bucketname, $username, $password, $api_domain)
    {
        $this->api_domain = $api_domain;
        $this->bucketname = $bucketname;
        $this->username = $username;
        $this->password = $password;
        $this->oss_client = new OssClient($this->username, $this->password, $this->api_domain, false);
    }

    /**
     * 切换 API 接口的域名.
     *
     * @param $domain {默然 v0.api.upyun.com 自动识别, v1.api.upyun.com 电信, v2.api.upyun.com 联通, v3.api.upyun.com 移动}
     * return null;
     */
    public function setApiDomain($domain)
    {
        $this->api_domain = $domain;
    }

    /**
     * 设置连接超时时间.
     *
     * @param $time 秒
     * return null;
     */
    public function setTimeout($time)
    {
        $this->timeout = $time;
    }

    /**
     * 设置待上传文件的 Content-MD5 值（如又拍云服务端收到的文件MD5值与用户设置的不一致，将回报 406 Not Acceptable 错误）.
     *
     * @param $str （文件 MD5 校验码）
     * return null;
     */
    public function setContentMD5($str)
    {
        $this->content_md5 = $str;
    }

    /**
     * 获取总体空间的占用信息
     * return 空间占用量，失败返回 null.
     */
    public function getBucketUsage()
    {
        return $this->getFolderUsage('/');
    }

    /**
     * 获取某个子目录的占用信息.
     *
     * @param $path 目标路径
     * return 空间占用量，失败返回 null
     */
    public function getFolderUsage($path)
    {
        return floatval(0);
    }

    /**
     * 设置待上传文件的 访问密钥（注意：仅支持图片空！，设置密钥后，无法根据原文件URL直接访问，需带 URL 后面加上 （缩略图间隔标志符+密钥） 进行访问）
     * 如缩略图间隔标志符为 ! ，密钥为 bac，上传文件路径为 /folder/test.jpg ，那么该图片的对外访问地址为： http://空间域名/folder/test.jpg!bac.
     *
     * @param $str （文件 MD5 校验码）
     * return null;
     */
    public function setFileSecret($str)
    {
        $this->file_secret = $str;
    }

    /**
     * 上传文件.
     *
     * @param $file 文件路径（包含文件名）
     * @param $datas 文件内容 或 文件IO数据流
     * @param $auto_mkdir =false 是否自动创建父级目录
     * return true or false
     */
    public function writeFile($file, $datas, $auto_mkdir = false)
    {
        $file = $this->getOssFile($file);
        $r = $this->oss_client->putObject($this->bucketname, $file, $datas);
        return !is_null($r);
    }

    /**
     * 获取上传文件后的信息（仅图片空间有返回数据）.
     *
     * @param $key 信息字段名（x-upyun-width、x-upyun-height、x-upyun-frames、x-upyun-file-type）
     * return value or NULL
     */
    public function getWritedFileInfo($key)
    {
        if (!isset($this->tmp_infos)) {
            return;
        }

        return $this->tmp_infos[$key];
    }

    /**
     * 读取文件.
     *
     * @param $file 文件路径（包含文件名）
     * @param $output_file 可传递文件IO数据流（默认为 null，结果返回文件内容，如设置文件数据流，将返回 true or false）
     * return 文件内容 或 null
     */
    public function readFile($file, $output_file = null)
    {
        $file = $this->getOssFile($file);
        return $this->oss_client->getObject($this->bucketname, $file);
    }

    /**
     * 获取文件信息.
     *
     * @param $file 文件路径（包含文件名）
     * return array('type'=> file | folder, 'size'=> file size, 'date'=> unix time) 或 null
     */
    public function getFileInfo($file)
    {
        $file = $this->getOssFile($file);
        $r = $this->oss_client->getObjectMeta($this->bucketname, $file);
        if (is_null($r)) {
            return null;
        }

        return $r;
    }

    /**
     * 读取目录列表.
     *
     * @param $path 目录路径
     * return array 数组 或 null
     */
    public function readDir($path)
    {
        $path = $this->getOssFile($path);
        return $this->fileList($path);
    }

    public function fileList($dir, $maxKey = 30, $delimiter = '/', $nextMarker = '')
    {
        $fileList = []; // 获取的文件列表, 数组的一阶表示分页结果
        $dirList = []; // 获取的目录列表, 数组的一阶表示分页结果
        $storageList = [
            'file' => [], // 真正的文件数组
            'dir' => [], // 真正的目录数组
        ];
        while (true) {
            $options = [
                'delimiter' => $delimiter,
                'prefix' => $dir,
                'max-keys' => $maxKey,
                'marker' => $nextMarker,
            ];
            try {
                $fileListInfo = $this->oss_client->listObjects($this->bucketname, $options);
                // 得到nextMarker, 从上一次 listObjects 读到的最后一个文件的下一个文件开始继续获取文件列表, 类似分页
            } catch (OssException $e) {
                error_log($e);
                return []; // 发送错误信息
            }
            $nextMarker = $fileListInfo->getNextMarker();
            $fileItem = $fileListInfo->getObjectList();
            $dirItem = $fileListInfo->getPrefixList();
            $fileList[] = $fileItem;
            $dirList[] = $dirItem;
            if ($nextMarker === '') break;
        }
        foreach ($fileList[0] as $item) {
            $storageList['file'][] = $this->objectInfoParse($item);
        }
        foreach ($dirList[0] as $item) {
            $storageList['dir'][] = $this->prefixInfoParse($item);
        }
        return $storageList; // 发送正确信息
    }

    /* 解析 prefixInfo 类 */
    private function prefixInfoParse(PrefixInfo $prefixInfo)
    {
        return [
            'dir' => $prefixInfo->getPrefix(),
        ];
    }

    /* 解析 objectInfo 类 */
    public function objectInfoParse(ObjectInfo $objectInfo)
    {
        return [
            'name' => $objectInfo->getKey(),
            'size' => $objectInfo->getSize(),
            'update_at' => $objectInfo->getLastModified(),
        ];
    }

    /**
     * 删除文件.
     *
     * @param $file 文件路径（包含文件名）
     * return true or false
     */
    public function deleteFile($file)
    {
        $file = $this->getOssFile($file);
        $r = $this->oss_client->deleteObject($this->bucketname, $file);
        return !is_null($r);
    }

    /**
     * 创建目录.
     *
     * @param $path 目录路径
     * @param $auto_mkdir =false 是否自动创建父级目录
     * return true or false
     */
    public function mkDir($path, $auto_mkdir = false)
    {
        $path = $this->getOssFile($path);
        $r = $this->oss_client->createObjectDir($this->bucketname, $path);
        return !is_null($r);
    }

    /**
     * 删除目录.
     *
     * @param $path 目录路径
     * return true or false
     */
    public function rmDir($dir)
    {
        //$r = $this->HttpAction('DELETE', $dir, null);
        return !is_null(1);
    }

    private function getOssFile($filename)
    {
        return substr($filename, 1);
    }
}
