<?php

declare(strict_types=1);
namespace Loom\Analytics;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
final class FileAnalyticsStore
{
 public function __construct(private string $rootDir,private ?EventValidator $validator=null){$this->rootDir=rtrim($rootDir,'/');$this->validator??=new EventValidator();}
 public function append(array $event):string{$event=$this->validator->validate($event);$date=(new DateTimeImmutable($event['occurred_at']))->setTimezone(new DateTimeZone('UTC'));$dir=sprintf('%s/events/%s/%s',$this->rootDir,$date->format('Y'),$date->format('m'));if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('Unable to create analytics directory.');$path=$dir.'/'.$date->format('d').'.ndjson';$handle=fopen($path,'ab');if($handle===false||!flock($handle,LOCK_EX))throw new RuntimeException('Unable to lock analytics file.');$line=json_encode($event,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";$written=fwrite($handle,$line);fflush($handle);flock($handle,LOCK_UN);fclose($handle);if($written!==strlen($line))throw new RuntimeException('Unable to append analytics event.');@chmod($path,0640);return $path;}
}
