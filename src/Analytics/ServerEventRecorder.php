<?php

declare(strict_types=1);
namespace Loom\Analytics;
use Throwable;
final class ServerEventRecorder
{
 public function __construct(private FileAnalyticsStore $store,private bool $enabled){}
 public function record(string $eventType,array $metadata=[]):bool{if(!$this->enabled)return false;$event=['event_id'=>'evt_'.bin2hex(random_bytes(16)),'event_type'=>$eventType,'occurred_at'=>gmdate('c'),'path'=>parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/','metadata'=>$metadata];foreach(['visitor_id'=>'loom_visitor_id','session_id'=>'loom_session_id'] as $key=>$cookie)if(isset($_COOKIE[$cookie])&&preg_match('/^[A-Za-z0-9_-]{16,80}$/',(string)$_COOKIE[$cookie]))$event[$key]=$_COOKIE[$cookie];try{$this->store->append($event);return true;}catch(Throwable){return false;}}
}
