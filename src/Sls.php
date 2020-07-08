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
        $log = $this->filterLogs($log);
        
        if (empty($log)) {
            return false;
        }
        
        if ($this->config[ 'use_fc' ]) {
            if (isset($GLOBALS[ 'fcLogger' ])) {
                return $this->fcLogger($log);
            }
        }
        
        $config  = $this->config[ 'credentials' ]
          ? $this->config[ 'credentials' ]
          : [
            'AccessKeyId'     => request()->server('context_credentials_accessKeyID'),
            'AccessKeySecret' => request()->server('context_credentials_accessKeySecret'),
            'endpoint'        => request()->server('context_region').'.sls.aliyuncs.com',//context_region.sls.aliyuncs.com
            'project'         => request()->server('context_service_logProject'),// context_service_logProject
            'logStore'        => request()->server('context_service_logStore'),// context_service_logStore
            'topic'           => request()->server('context_service_name'),// context_service_name
            'token'           => request()->server('context_credentials_securityToken'),
          ];
        $logInfo = [];
        $item    = ['source' => $this->config[ 'source' ]];
        if (request()->server('context_function_name', '')) {
            $item[ 'functionName' ] = request()->server('context_function_name', '');
        }
        if (request()->server('context_service_qualifier', '')) {
            $item[ 'qualifier' ] = request()->server('context_service_qualifier', '');
        }
        if (request()->server('context_service_name', '')) {
            $item[ 'serviceName' ] = request()->server('context_service_name', '');
        }
        if (request()->server('context_service_versionId', '')) {
            $item[ 'versionId' ] = request()->server('context_service_versionId', '');
        }
        
        $msgPre = (new \DateTime())->format('c');
        
        if (request()->server('context_requestId', '')) {
            $msgPre .= ' '.(request()->server('context_requestId', ''));
        }
        
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
            $item[ 'message' ] = sprintf("%s [%s] %s", $msgPre, strtoupper($type), implode(';', $message));
            $logInfo[]         = new LogItem($item);
        }
        
        if (empty($logInfo)) {
            return false;
        }
        
        $putLogsRequest = new PutLogsRequest(
          $config[ 'project' ], $config[ 'logStore' ], $config[ 'topic' ], $this->config[ 'source' ], $logInfo
        );
        $client         = new Client(
          $config[ 'endpoint' ], $config[ 'AccessKeyId' ], $config[ 'AccessKeySecret' ], $config[ 'token' ] ?? ''
        );
        try {
            $client->putLogs($putLogsRequest);
        }
        catch (\Exception $e) {
            $this->throwSelfException($e);
        }
        
        return true;
    }
    
    /**
     *
     * 不记录自身的错误
     *
     * @param array $log
     *
     * @return array
     */
    protected function filterLogs(array $log) : array
    {
        return array_filter(
          $log,
          function ($item) {
              return !is_string($item) || strpos($item, "[".self::class."::error]") === false;
          }
        );
    }
    
    /**
     *
     * 抛出错误
     *
     * @param \Exception $exception
     *
     * @throws \Exception
     */
    protected function throwSelfException(\Exception $exception)
    {
        throw new \Exception("[".self::class."::error]".$exception->getMessage());
    }
    
    /**
     * @param array $log
     *
     * @return bool
     */
    protected function fcLogger(array $log) : bool
    {
        $logger = $GLOBALS[ 'fcLogger' ];
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
            $info = implode(';', $message);
            if (method_exists($logger, $type)) {
                $logger->$type($info);
            }
            else {
                $logger->info($info);
            }
        }
        
        return true;
    }
    
}