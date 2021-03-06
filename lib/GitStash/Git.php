<?php

namespace GitStash;

use Doctrine\Common\Proxy\Exception\InvalidArgumentException;
use GitStash\Git\Blob;
use GitStash\Git\Commit;
use GitStash\Git\Tree;
use GitStash\Git\TreeItem;

class Git {

    protected $path;
    protected $process;
    protected $pipes;

    function __construct($path)
    {
        $this->path = $path;

        $this->proc = null;
    }

    function __destruct()
    {
        $this->processClose();
    }

    protected function processOpen()
    {
        if ($this->process) {
            return;
        }

        $descriptors = array(
           0 => array("pipe", "r"),
           1 => array("pipe", "w"),
        );

        $this->process = proc_open("git --git-dir=".$this->path." cat-file --batch", $descriptors, $this->pipes);
    }

    protected function processClose()
    {
        if (! $this->process) {
            return;
        }

        fclose($this->pipes[0]);
        fclose($this->pipes[1]);

        proc_close($this->process);
    }

    /**
     * @param $sha
     * @return Object
     */
    function fetchObject($sha)
    {
        $this->processOpen();

        // Write sha to git-cat-file
        fwrite($this->pipes[0], "$sha\n");

        // Read info back
        do {
            $info = trim(fgets($this->pipes[1]));
        } while (! strlen($info));

        // Read sha, type, size
        $info = array_combine(array('sha', 'type', 'size'), explode(" ", $info));

        switch ($info['type']) {
            case 'blob' :
                return $this->readBlob($info);
                break;
            case 'commit' :
                return $this->readCommit($info);
                break;
            case 'tree' :
                return $this->readTree($info);
                break;
        }

        throw new \RuntimeException("Invalid type");
    }

    protected function readBlob(array $info)
    {
        $content = fread($this->pipes[1], $info['size']);

        return new Blob($info['sha'], $content);
    }

    protected function readTree(array $info)
    {
        // Read remainder bytes (based on found size)
        $details = trim(fread($this->pipes[1], $info['size']));

        preg_match_all('/([0-7]+) ([^\x00]+)\x00(.{20})/s', $details, $matches);

        $tree = array();
        foreach (array_keys($matches[0]) as $k) {
            $tree[] = array(
                'perm' => $matches[1][$k],
                'name' => $matches[2][$k],
                'sha' => bin2hex($matches[3][$k]),
            );
        }

        return new Tree($info['sha'], $tree);
    }

    protected function readCommit(array $info)
    {
        // Read remainder bytes (based on found size)
        $details = trim(fread($this->pipes[1], $info['size']));

        // Parse headers until first empty \n
        $commit = array(
            'tree' => null,
            'parent' => null,
            'committer' => null,
            'author' => null,
            'log' => null,
            'log_details' => null,
        );
        do {
            list($line, $details) = explode("\n", $details, 2);

            if (strlen($line) == 0) break;

            list($type, $type_info) = explode(" ", $line, 2);
            $commit[$type] = $type_info;
        } while (strlen($line));

        // Parse commit log line and details (remainder lines)
        $details = explode("\n", $details, 2);
        $commit['log'] = $details[0];
        $commit['log_details'] = isset($details[1]) ? $details[1] : "";

        return new Commit(
            $info['sha'],
            $commit['tree'],
            $commit['parent'],
            $commit['committer'],
            $commit['author'],
            $commit['log'],
            $commit['log_details']
        );
    }

    function getRefs($type)
    {
        // Add file system refs
        $it = new \FilesystemIterator($this->path."/refs/".$type, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME);
        $ret = array();
        foreach ($it as $path) {
            $ret[basename($path)] = trim(file_get_contents($path));
        }

        // Add packed refs
        $refs = file($this->path . "/packed-refs");
        foreach ($refs as $line) {
            $line = trim($line);
            if ($line[0] == '#') continue;
            list($sha, $ref) = explode(" ", $line, 2);

            $a = explode('/', $ref);
            $a = array_splice($a, 2);
            $ref = join('/', $a);
            $ret[$ref] = $sha;
        }

        // Sort refs
        ksort($ret);

        return $ret;
    }

    /**
     * Returns the sha the reference points to
     *
     * @param $ref
     * @param string $base
     * @return string
     */
    function refToSha($ref, $base = 'heads') {
        $refs = $this->getRefs($base);
        if (! isset($refs[$ref])) {
            throw new InvalidArgumentException('Ref $base/$ref not found');
        }

        return $refs[$ref];
    }

    /**
     * Returns a ref that has been packed (ie: located not in the /refs directory, but in the /packed-refs file)
     *
     * @param $wantedRef
     * @return mixed
     */
    protected function findPackedRef($wantedRef) {
        $refs = file($this->path . "/packed-refs");

        foreach ($refs as $line) {
            $line = trim($line);
            if ($line[0] == '#') continue;
            list($sha, $ref) = explode(" ", $line, 2);
            if ($ref == $wantedRef) {
                return $sha;
            }
        }

        throw new InvalidArgumentException('Ref $wantedRef not found in packed refs');
    }

//    /**
//     * @return mixed
//     */
//    function getHead() {
//        $head = trim(file_get_contents($this->path . "/HEAD"));
//        $head = explode(": ", $head, 2);
//
//        // Fetch path
//        $path = $this->path . "/" . $head[1];
//
//        if (file_exists($path)) {
//            // regular ref
//            $sha = trim(file_get_contents($path));
//        } else {
//            // packed ref
//            $sha = $this->findPackedRef($head[1]);
//        }
//
//        return $this->fetchSha($sha);
//    }

}
