<?php

namespace App\Console\Commands;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ConvertPostmanCollectionExportFileToMarkdowns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'my:conv-postman-markdown {output} {apikey} {workspace} {--collection=*}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert Postman Export File to Markdowns';
    
    protected $outputDir = null;
    
    protected $apiKey = null;

    protected $pm = null;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->readDataAndCleanupOutputDir();
        
        // read & process collections of specific workspace
        $requireCollections = $this->option('collection');
        $collections = $this->readWorkspace($this->argument('workspace'));
        foreach ($collections as $collection)
        {
            if (!empty($requireCollections) && !in_array($collection['uid'], $requireCollections)) {
                $this->line("Skip collection: {$collection['name']}.");
                continue;
            }
            
            $this->line("Collection: [{$collection['name']}] is processing...");
            $dir = $this->getDir($this->outputDir, $collection['name']);
            $pm = $this->readCollection($collection, $dir);
            $this->processCollectionInfo($pm, $dir);
            $this->processCollectionItems($pm, $dir, 'item');
            $this->line("Collection: [{$collection['name']}] 處理完畢!");
        }
    }

    protected function processCollectionInfo($pm, $dir)
    {
        $name = data_get($pm, 'info.name');
        $description = data_get($pm, 'info.description');
        
        if (!empty($description))
        {
            $path = $this->getPath($dir, "{$name}.md");
            $this->writeMarkdown($description, $path, $name);
        }
    }
    
    protected function processCollectionItems($pm, $dir, $key)
    {
        $items = data_get($pm, $key);
        if (!is_array($items)) {
            throw new Exception("key: [{$key}] is not array.");
        }
        
        foreach ($items as $i => $item)
        {
            $name = data_get($pm, "{$key}.{$i}.name");
            $request = data_get($pm, "{$key}.{$i}.request");
            if (is_null($request)) {
                // it's a folder
                $this->processCollectionItems($pm, $this->getDir($dir, $name), "{$key}.{$i}.item");
            }
            else {
                // it's a request
                $markdown = sprintf('### `%s` %s%s', 
                    data_get($request, 'method'),
                    data_get($request, 'url.raw'),
                    $this->newline());
                
                
                $description = data_get($request, 'description');
                $path = $this->getPath($dir, "{$name}.md");
                $this->writeMarkdown($markdown . $description, $path, $name);
            }
        }
    }
    
    protected function getPath($dir, $filename)
    {
        return $dir . DIRECTORY_SEPARATOR . $this->trimSlashes($filename);
    }

    protected function getDir($dir, $subDir = null)
    {
        if (!is_null($subDir)) {
            $dir = $dir . DIRECTORY_SEPARATOR . $this->trimSlashes($subDir);
        }
        
        if (file_exists($dir)) {
            if (is_file($dir)) {
                throw new Exception("Output: [$dir] is a file.");
            }
        }
        else {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }
    
    protected function getPostmanApiData($url, $filePath = null)
    {
        $client = new Client();
        $resp = $client->request('GET', $url, [
            'headers' => [
                'X-API-Key' => $this->apiKey,
            ],
            'verify' => false,
            'base_uri' => 'https://api.getpostman.com',
        ]);
        
        if (is_null($filePath)) {
            return json_decode($resp->getBody(), true);
        }
        else {
            $content = $resp->getBody()->getContents();
            file_put_contents($filePath, $content);
            
            return json_decode($content, true);
        }
    }

    protected function newline()
    {
        return PHP_EOL . PHP_EOL;
    }
    
    protected function readCollection($collection, $dir)
    {
        $filepath = $this->getPath($dir, 'collection.json');
        $data = $this->getPostmanApiData("collections/{$collection['uid']}", $filepath);
        
        if (!is_array($data) || data_get($data, 'collection.info.schema') != 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json') {
            throw new Exception("Collection 資料不正確! 請查看 [{$filepath}]");
        }
        
        $this->line('collection.json 下載完成');
        return data_get($data, 'collection');
    }

    protected function readDataAndCleanupOutputDir()
    {
        $this->apiKey = $this->argument('apikey');
        $this->outputDir = $this->getDir(rtrim($this->argument('output'), '\\/'));
        
        //clean up dir
        $this->deleteDirectory($this->outputDir);
    }

    protected function readWorkspace($workspaceId)
    {
        $filepath = $this->getPath($this->outputDir, 'workspace.json');
        $data = $this->getPostmanApiData("workspaces/{$workspaceId}", $filepath);
        
        if (is_array($data) && isset($data['workspace'])) {
            $this->line('workspace.json 下載完成');
            return data_get($data, 'workspace.collections');
        }
        else {
            throw new Exception("Workspace 資料不正確! 請查看 [{$filepath}]");
        }
    }
    
    protected function trimSlashes($input)
    {
        return preg_replace('/[\/\\\]/', '', $input);
    }

    protected function writeMarkdown($raw, $path, $title = null)
    {
        $title = empty($title) ? '' : '# ' . $title . $this->newline();
        file_put_contents($path, $title . stripcslashes($raw));
    }
    
    protected function deleteDirectory($dir)
    {
        $handle = opendir($dir);
        while (false !== ($i = readdir($handle)))
        {
            $path = $dir . DIRECTORY_SEPARATOR . $i;
            if (strpos($i, '.') === 0) {
                // it is current dir, sup dir or hidden file
                continue;
            }
            
            if (is_dir($path)) {
                $this->line("[{$path}] is deleting...");
                $this->deleteDirectory($path);
                rmdir($path);
            }
            else {
                unlink($path);
            }
        }
        closedir($handle);
    }
}
