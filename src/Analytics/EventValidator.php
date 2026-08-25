<?php

declare(strict_types=1);
namespace Loom\Analytics;
use InvalidArgumentException;
final class EventValidator
{
 private const TYPES=['page_view','session_start','cta_click','form_start','form_submit','lead_created','signup_started','signup_completed','conversion'];
 public function validate(array $event): array { $type=$event['event_type']??null; if(!is_string($type)||(!in_array($type,self::TYPES,true)&&!preg_match('/^custom_[a-z0-9_]{1,50}$/',$type))) throw new InvalidArgumentException('Unsupported analytics event type.'); $event['occurred_at']??=gmdate('c'); if(!is_string($event['occurred_at'])||strtotime($event['occurred_at'])===false) throw new InvalidArgumentException('Invalid analytics timestamp.'); foreach(['event_id','visitor_id','session_id'] as $key) if(isset($event[$key])&&(!is_string($event[$key])||!preg_match('/^[A-Za-z0-9_-]{16,80}$/',$event[$key]))) throw new InvalidArgumentException('Invalid analytics identifier.'); if(isset($event['path'])&&(!is_string($event['path'])||!str_starts_with($event['path'],'/'))) throw new InvalidArgumentException('Invalid analytics path.'); if(isset($event['metadata'])){$json=json_encode($event['metadata'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(!is_array($event['metadata'])||$json===false||strlen($json)>2048)throw new InvalidArgumentException('Invalid analytics metadata.');$this->reject($event['metadata']);} return $event; }
 private function reject(array $value):void{foreach($value as $key=>$child){if(in_array(strtolower((string)$key),['address','email','firstname','ip','message','name','phone','surname'],true))throw new InvalidArgumentException('Analytics metadata contains sensitive data.');if(is_array($child))$this->reject($child);}}
}
