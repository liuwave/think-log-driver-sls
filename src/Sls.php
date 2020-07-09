<?php
/**
 * Created by PhpStorm.
 * User: liuwave
 * Date: 2020/7/3 11:05
 * Description:
 */

declare (strict_types=1);

namespace liuwave\think\log\driver;

use Aliyun\SLS\Client;
use Aliyun\SLS\Models\LogItem;
use Aliyun\SLS\Requests\PutLogsRequest;
use DateTime;
use Exception;
use think\App;
use think\contract\LogHandlerInterface;

/**
 * Class Sls
 * @package liuwave\think\log\driver
 */
class Sls implements LogHandlerInterface
{
    
    /**
     * 配置参数
     * @var array
     */
    protected $config = [
      'debug'        => false,
      'json'         => false,
      'json_options' => JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
      
      'use_fc'      => true,
      'source'      => 'think',
      'credentials' => [
        'AccessKeyId'     => '',
        'AccessKeySecret' => '',
        'endpoint'        => 'cn-beijing.sls.aliyuncs.com',//context_region.sls.aliyuncs.com
        'project'         => '',// context_service_logProject
        'logStore'        => '',// context_service_logStore
        'topic'           => '',// context_service_name
      ],
    ];
    
    /**
     * Sls constructor.
     *
     * @param \think\App $app
     * @param array      $config
     */
    public function __construct(App $app, array $config = [])
    {
        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
        }
        
        if (!isset($config[ 'debug' ])) {
            $this->config[ 'debug' ] = $app->isDebug();
        }
    }
    
    /**
     * @inheritDoc
     * @throws \Exception
     */
    public function save(array $log) : bool
    {
        $messages = $this->parserLogs($log);
        
        if (empty($messages)) {
            return false;
        }
        
        if ($this->config[ 'use_fc' ] && isset($GLOBALS[ 'fcLogger' ])) {
            return $this->fcLogger($messages);
        }
        
        return $this->sdkLogger($messages);
    }
    
    protected function parserLogs(array $log) : array
    {
        $log = array_filter(
          $log,
          function ($item) {
              return !is_string($item) || strpos($item, "[".self::class."::error]") === false;
          }
        );
        
        $messages = [];
        foreach ($log as $type => $val) {
            $message = [];
            foreach ($val as $msg) {
                if (!is_string($msg)) {
                    $msg = var_export($msg, true);
                }
                $message[] = $this->config[ 'json' ] ? json_encode(
                  ['msg' => $msg],
                  $this->config[ 'json_options' ]
                ) : $msg;
            }
            $messages[] = [
              'type'    => $type,
              'message' => implode(';', $message),
            ];
        }
        
        return $messages;
    }
    
    /**
     *
     * 抛出错误
     *
     * @param \Exception $exception
     *
     * @throws \Exception
     */
    protected function throwSelfException(Exception $exception)
    {
        throw new Exception("[".self::class."::error]".$exception->getMessage());
    }
    
    /**
     * @param array $messages
     *
     * @return bool
     * @throws \Exception
     */
    protected function sdkLogger(array $messages) : bool
    {
        $item = [];
        if (!empty($this->config[ 'source' ])) {
            $item[ 'source' ] = $this->config[ 'source' ];
        }
        //判断是否为 函数计算环境
        if (getenv('FC_SERVER_PATH')) {
            $item[ 'qualifier' ]    = getenv('FC_QUALIFIER') ? : '';
            $item[ 'functionName' ] = request()->server('context_function_name') ? : '';
            $item[ 'serviceName' ]  = request()->server('context_service_name');
            $item[ 'versionId' ]    = request()->server('context_service_versionId');
        }
        
        $msgPre = ((new DateTime())->format('c')).' '.(request()->server('context_requestId'));
        
        $logInfo = array_map(
          function ($message) use ($msgPre) {
              $item[ 'message' ] = sprintf("%s [%s] %s", $msgPre, strtoupper($message[ 'type' ]), $message[ 'message' ]);
              
              return new LogItem($item);
          },
          $messages
        );
        
        $config = $this->config[ 'credentials' ]
          ? : [
            'AccessKeyId'     => getenv('accessKeyID') ? : '',
            'AccessKeySecret' => getenv('accessKeySecret') ? : '',
            'endpoint'        => request()->server('context_region').'.sls.aliyuncs.com',//context_region.sls.aliyuncs.com
            'project'         => request()->server('context_service_logProject'),// context_service_logProject
            'logStore'        => request()->server('context_service_logStore'),// context_service_logStore
            'topic'           => getenv('topic') ? : '',
            'token'           => getenv('securityToken') ? : "",
          ];
        
        try {
            (new Client(
              $config[ 'endpoint' ], $config[ 'AccessKeyId' ], $config[ 'AccessKeySecret' ], $config[ 'token' ] ?? ''
            ))->putLogs(
              new PutLogsRequest(
                $config[ 'project' ], $config[ 'logStore' ], $config[ 'topic' ], $this->config[ 'source' ], $logInfo
              )
            );
        }
        catch (Exception $e) {
            $this->throwSelfException($e);
        }
        
        return true;
    }
    
    /**
     * @param array $messages
     *
     * @return bool
     */
    protected function fcLogger(array $messages) : bool
    {
        foreach ($messages as $message) {
            $type = method_exists($GLOBALS[ 'fcLogger' ], $message[ 'type' ]) ? $message[ 'type' ] : 'info';
            $GLOBALS[ 'fcLogger' ]->$type($message[ 'message' ]);
        }
        
        return true;
    }
    
}