<?php

declare(strict_types=1);
namespace Loom\Analytics;
use InvalidArgumentException;
use JsonException;
use Throwable;
final class AnalyticsEndpoint
{
 public function __construct(private FileAnalyticsStore $store,private bool $enabled){}
 public function process(string $method,string $body):array{if(!$this->enabled)return['status'=>404,'body'=>'{"error":"not_found"}'];if($method!=='POST')return['status'=>405,'body'=>'{"error":"method_not_allowed"}'];if(strlen($body)>16384)return['status'=>413,'body'=>'{"error":"payload_too_large"}'];try{$event=json_decode($body,true,16,JSON_THROW_ON_ERROR);if(!is_array($event))return['status'=>400,'body'=>'{"error":"invalid_event"}'];$event['event_id']??='evt_'.bin2hex(random_bytes(16));$this->store->append($event);return['status'=>202,'body'=>'{"accepted":true}'];}catch(JsonException|InvalidArgumentException){return['status'=>400,'body'=>'{"error":"invalid_event"}'];}catch(Throwable){return['status'=>503,'body'=>'{"error":"analytics_unavailable"}'];}}
}
