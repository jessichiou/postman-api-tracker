<?php

namespace App\Console\Commands;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ConvertPostmanCollectionExportFileToMarkdowns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'my:conv-postman-markdown {output} {apikey} {workspace} {gitlabKey} {gitlabProject} {--collection=*}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert Postman Export File to Markdowns';
    
    protected $outputDir = null;
    
    protected $apiKey = null;
    
    protected $gitlabKey = null;
    
    protected $gitlabProject = null;

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
        $this->log("[START] {$this->description} at " . $this->getTimeString());
        $this->readDataAndCleanupOutputDir();
        
        // read & process collections of specific workspace
        $requireCollections = $this->option('collection');
        $collections = $this->readWorkspace($this->argument('workspace'));
        foreach ($collections as $collection)
        {
            if (!empty($requireCollections) && !in_array($collection['uid'], $requireCollections)) {
                $this->log("Skip collection: {$collection['name']}.");
                continue;
            }
            
            $this->log("Collection: [{$collection['name']}] is processing...");
            $dir = $this->getDir($this->outputDir, $collection['name']);
            $pm = $this->readCollection($collection, $dir);
            $this->processCollectionInfo($pm, $dir);
            $this->processCollectionItems($pm, $dir, 'item');
            $this->log("Collection: [{$collection['name']}] 處理完畢!");
        }
        
        // git
        $this->processGitCommit();
        $this->log("[END] {$this->description} at " . $this->getTimeString());
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
    
    protected function processGitCommit()
    {
        $checkGitStatus = exec("cd {$this->outputDir} && git status");
        if ($checkGitStatus == 'nothing to commit, working tree clean') {
            $this->log("Collections did not change at " . $this->getTimeString());
            return;
        }
        
        // commit & push to gitlab
        exec(sprintf('cd %s && git add . && git commit -m "requests fetched at %s" && git push', $this->outputDir, $this->getTimeString()));
        
        $commit = $this->getGitCommitId();
        $page = 1;
        do
        {
            $diffFiles = $this->getGitCommitDiff($commit, $page++);
            foreach ($diffFiles as $file)
            {
                if ($file['new_file']) {
                    $this->createGitIssue($commit, $file);
                }
                else if ($file['deleted_file']) {
                    $this->deleteGitIssue($commit, $file);
                }
                else if ($file['renamed_file']) {
                    $this->log('非預期的 git diff 內容: 發生 rename 事件', $file, 'warning');
                }
                else {
                    $this->updateGitIssue($commit, $file);
                }
            }
        }
        while (!empty($diffFiles));
    }
    
    protected function getGitCommitId()
    {
        exec(sprintf('cd %s && git log', $this->outputDir), $out);
        Log::debug('git log', $out);
        if (preg_match('/commit\s(\w+)/', data_get($out, '0'), $vars)) {
            return $vars[1];
        }
        throw new Exception('無法取得 git commit id!', $out);
    }
    
    protected function getGitCommitDiff($commit, $page)
    {
        return $this->getGitlabApiData('GET', "repository/commits/{$commit}/diff?page={$page}");
    }
    
    protected function getGitIssue($file)
    {
        return data_get($this->getGitlabApiData('GET', "issues?search=" . urlencode($file['new_path'])), '0');
    }

    protected function createGitIssue($commit, $file)
    {
        $issue = $this->getGitIssue($file);
        
        if (is_null($issue)) 
        {
            $collection = preg_replace('/[\/\\\].*/', '$1', $file['new_path']);
            $resp = $this->getGitlabApiData('POST', 'issues', [
                'form_params' => [
                    'assignee_id' => 3558728,
                    'issue_type' => 'incident',
                    'labels' => "create,collection:{$collection}",
                    'title' => $file['new_path'],
                    'description' => $this->getGitlabNoteBody($commit, $file['diff']),
                ],
            ]);

            $this->log('Create Issue: ' . data_get($resp, 'web_url'));
        }
        else
        {
            return $this->updateGitIssue($commit, $file, $issue);
        }
    }
    
    protected function updateGitIssue($commit, $file, $issue = null)
    {
        if (is_null($issue))
        {
            $issue = $this->getGitIssue($file);
            if (is_null($issue)) {
                return $this->createGitIssue($commit, $file);
            }
        }
        
        // Add Comment
        $resp = $this->getGitlabApiData('POST', "issues/{$issue['iid']}/notes", [
            'form_params' => [
                'body' => $this->getGitlabNoteBody($commit, $file['diff']),
            ],
        ]);

        // Change Status
        $options = [];
        if (!is_null($issue['closed_at'])) {
            $options['state_event'] = 'reopen';
        }
        if (in_array('delete', $issue['labels'])) {
            $options['remove_labels'] = 'delete';
        }
        
        if (count($options) > 0) {
            $this->getGitlabApiData('PUT', "issues/{$issue['iid']}", ['form_params' => $options]);
            $this->log('Update Issue: ' . data_get($issue, 'web_url'));
        }
        else {
            $this->log('Add Comment on Issue: ' . data_get($issue, 'web_url'));
        }
    }
    
    protected function deleteGitIssue($commit, $file)
    {
        $issue = $this->getGitIssue($file);
        if (is_null($issue)) {
            return;
        }
        
        if (in_array('delete', $issue['labels'])) {
            // Add Comment
            $resp = $this->getGitlabApiData('POST', "issues/{$issue['iid']}/notes", [
                'form_params' => [
                    'body' => 'found a delete commit.',
                ],
            ]);
            $this->log('Add Comment on Issue: ' . data_get($issue, 'web_url'));
        }
        else {
            $options = ['add_labels' => 'delete'];
            if (!is_null($issue['closed_at'])) {
                $options['state_event'] = 'reopen';
            }
            $this->getGitlabApiData('PUT', "issues/{$issue['iid']}", ['form_params' => $options]);
            $this->log('Delete Issue: ' . data_get($issue, 'web_url'));
        }
    }
    
    protected function getGitlabNoteBody($commit, $diff)
    {
        return "<details><summary>Show detail: {$commit}</summary>\n\n```diff\n" . stripcslashes($diff) . "\n```\n\n</details>";
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
    
    protected function getGitlabApiData($method, $url, $options = [])
    {
        // 別打太快
        sleep(1);
        
        $client = new Client();
        $resp = $client->request($method, "https://gitlab.com/api/v4/projects/{$this->gitlabProject}/{$url}", array_merge([
            'headers' => [
                'PRIVATE-TOKEN' => $this->gitlabKey,
            ],
            'verify' => false,
        ], $options));
        
        return json_decode($resp->getBody(), true);
    }
    
    protected function getTimeString()
    {
        return now()->timezone('Asia/Taipei')->format('Y-m-d H:i:s');
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
        
        $this->log('collection.json 下載完成');
        return data_get($data, 'collection');
    }

    protected function readDataAndCleanupOutputDir($cleanup = true)
    {
        $this->apiKey = $this->argument('apikey');
        $this->outputDir = $this->getDir(rtrim($this->argument('output'), '\\/'));
        $this->gitlabKey = $this->argument('gitlabKey');
        $this->gitlabProject = $this->argument('gitlabProject');
        
        //clean up dir
        if ($cleanup) {
            $this->deleteDirectory($this->outputDir);
        }
    }

    protected function readWorkspace($workspaceId)
    {
        $filepath = $this->getPath($this->outputDir, 'workspace.json');
        $data = $this->getPostmanApiData("workspaces/{$workspaceId}", $filepath);
        
        if (is_array($data) && isset($data['workspace'])) {
            $this->log('workspace.json 下載完成');
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
                //$this->log("[{$path}] is deleting...");
                $this->deleteDirectory($path);
                rmdir($path);
            }
            else {
                unlink($path);
            }
        }
        closedir($handle);
    }
    
    protected function log($message, $data = null, $level = 'info')
    {
        switch ($level)
        {
            case 'error':
            case 'warn':
                $this->{$level}($message);
                break;
            
            case 'debug':
                $this->comment($message);
                break;
            
            case 'info':
                $this->line($message);
                break;
            
            default:
                throw new Exception('Invalid Log Level');
        }
        
        if (is_array($data)) {
            Log::{$level}($message, $data);
        }
        else {
            Log::{$level}($message);
        }
    }
}
