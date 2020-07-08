# think-log-driver-sls
这是使用阿里云日志服务sls的thinkphp6.0日志驱动，同时支持函数计算

参见 [liuwave/fc-thinkphp](https://github.com/liuwave/fc-thinkphp)


## 安装

    composer require liuwave/think-log-driver-sls
    
    
## 使用

 修改`config/log.php`:

1. 将`'default'      => env('log.channel', 'file'),` 修改成`'default'      => env('log.channel', 'sls'),`
2. 在`channels`下添加日志通道：

```php
    'sls' =>  [
            'type'  => \liuwave\think\log\driver\Sls::class,
            'debug' => false,
            'json'  => false,
              // 关闭通道日志写入
            'close'          => false,
              // 是否实时写入
            'realtime_write' => true,
            
            'use_fc'         => true,//是否使用函数计算内置的 fcLogger，
            //仅在函数计算 php runtime cli模式下有效，若为true,则以下配置可不填
            //若设置为true，但不在函数计算 cli模式下，会尝试使用日志服务 php SDK
            //参见[相关参考](#相关参考)
            'source'         => 'think',//来源
            'credentials'    => [//认证信息,false或array，若为false，则默认使用函数计算提供的context信息中的认证信息，
                                 //如设置为false，且不在函数计算环境下，则不会写入日志
              'AccessKeyId'     => 'your_access_key_id',
              'AccessKeySecret' => 'your_access_key_secret',
              'endpoint'        => 'cn-beijing.sls.aliyuncs.com',//region.sls.aliyuncs.com
              'project'         => 'your_sls_project_name',// 
              'logStore'        => 'your_sls_log_store_name',// 
              'topic'           => '',// 
            ],
          ]

``` 

## 相关参考

- [liuwave/fc-thinkphp](https://github.com/liuwave/fc-thinkphp)
- [函数计算PHP运行环境](https://help.aliyun.com/document_detail/89032.html?source=5176.11533457&userCode=re2rax3m&type=copy)
- [$context 参数](https://help.aliyun.com/document_detail/89029.html?source=5176.11533457&userCode=re2rax3m&type=copy)
