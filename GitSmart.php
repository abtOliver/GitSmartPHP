<?php

define("DEBUG_LOG", false);
define("HTTP_AUTH", false);
define("GZIP_SUPPORT", false);
if (isset($_ENV['OPENSHIFT_DATA_DIR'])) {
    define("GIT_ROOT", $_ENV['OPENSHIFT_DATA_DIR'] . "git");
} else {
    define("GIT_ROOT", "./git");
}
define("GIT_HTTP_BACKEND", "/usr/lib/git-core/git-http-backend");
define("GIT_BIN", "/usr/bin/git");
define("REMOTE_USER", "smart-http");
define("LOG_RESPONSE", "response.log");
define("LOG_PROCESS", "process.log");

$gitSmart = new GitSmart(GIT_ROOT);

if (HTTP_AUTH) {
    if (!isset($_SERVER["PHP_AUTH_USER"])) {
        header("WWW-Authenticate: Basic realm=\"Git\"");
        header("HTTP/1.0 401 Unauthorized");
        exit;
    }
}

$pathInfo = $_SERVER['PATH_INFO'];
$pathItems = isset($pathInfo) ? explode('/', $pathInfo) : array('', '');

if ($pathItems[1] == '')
    $pathItems[1] = 'admin';

if (!isset($pathInfo) || strtolower($pathItems[1]) == 'admin') {
    if (isset($_GET["admin"]) || strtolower($pathItems[1]) == 'admin') {
        if (isset($_GET["action"])) {

            $repo = trim($_GET['repo'] . "");

            try {
                if ($_GET["action"] == "new") {

                    $gitSmart->newRepo($repo);
                } else if ($_GET["action"] == "delete") {

                    $gitSmart->deleteRepo($repo);

                    $log = "Delete repo '" . $repo . "' success ..." . PHP_EOL;
                    echo $log . "<br><br>";
                    file_put_contents(LOG_RESPONSE, $log, FILE_APPEND);
                }
            } catch (Exception $exc) {

                $log = $exc->getMessage() . "<br><br>" . PHP_EOL;
                echo $log . "<br><br>";
                file_put_contents(LOG_RESPONSE, $log, FILE_APPEND);
            }
        }

        if (!file_exists(GIT_ROOT)) {
            mkdir(GIT_ROOT);
        }
        if (file_exists(GIT_ROOT . "/")) {
            foreach (scandir(GIT_ROOT . "/") as $repo) {
                if (!in_array($repo, array(".", "..", "_temp"))) {
                    echo "<form method=\"get\" action=\"" . $_SERVER["PHP_SELF"] . "\">" . PHP_EOL;
                    echo "<input type=\"hidden\" name=\"admin\">" . PHP_EOL;
                    echo "<input type=\"hidden\" name=\"repo\" value=\"" . $repo . "\">" . PHP_EOL;
                    echo "<input type=\"submit\" name=\"action\" value=\"delete\" onclick='return confirm(\"Are you sure?\")'>" . PHP_EOL;
                    echo $repo . " - " . PHP_EOL;
                    system("GIT_WORK_TREE=" . GIT_ROOT . "/" . $repo . " GIT_DIR=" . GIT_ROOT . "/" . $repo . " " . GIT_BIN . " rev-list --count HEAD");
                    echo "</form>" . PHP_EOL;
                }
            }
            echo "<form method=\"get\" action=\"" . $_SERVER["PHP_SELF"] . "\">" . PHP_EOL;
            echo "<input type=\"hidden\" name=\"admin\">" . PHP_EOL;
            echo "<input type=\"submit\" name=\"action\" value=\"new\">" . PHP_EOL;
            echo "<input type=\"text\" name=\"repo\">" . PHP_EOL;
            echo "</form>" . PHP_EOL;
            echo "<form method=\"get\" action=\"" . $_SERVER["PHP_SELF"] . "\">" . PHP_EOL;
            echo "<input type=\"hidden\" name=\"admin\">" . PHP_EOL;
            echo "<input type=\"submit\" name=\"action\" value=\"list\">" . PHP_EOL;
            echo "</form>" . PHP_EOL;
        } else {
            echo "Git repo not exists.<br><br>" . PHP_EOL;
        }
        exit;
    }
}

if (isset($_SERVER["PATH_INFO"])) {
    list($git_project_path, $path_info) = $temp = preg_split("/\//", $_SERVER["PATH_INFO"], 2, PREG_SPLIT_NO_EMPTY);
    $git_project_path = "/" . $git_project_path . "/";
    $path_info = "/" . $path_info;
} else {
    $git_project_path = "/";
    $path_info = "";
}

$request_headers = getallheaders();
$php_input = file_get_contents("php://input");
$env = array
    (
    "GIT_PROJECT_ROOT" => GIT_ROOT . $git_project_path,
    "GIT_HTTP_EXPORT_ALL" => "1",
    "REMOTE_USER" => isset($_SERVER["REMOTE_USER"]) ? $_SERVER["REMOTE_USER"] : REMOTE_USER,
    "REMOTE_ADDR" => isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : "",
    "REQUEST_METHOD" => isset($_SERVER["REQUEST_METHOD"]) ? $_SERVER["REQUEST_METHOD"] : "",
    "PATH_INFO" => $path_info,
    "QUERY_STRING" => isset($_SERVER["QUERY_STRING"]) ? $_SERVER["QUERY_STRING"] : "",
    "CONTENT_TYPE" => isset($request_headers["Content-Type"]) ? $request_headers["Content-Type"] : "",
);

$settings = array
    (
    0 => array("pipe", "r"),
    1 => array("pipe", "w"),
);
if (DEBUG_LOG) {
    $settings[2] = array("file", LOG_PROCESS, "a");
}
$process = proc_open("\"" . GIT_HTTP_BACKEND . "\"", $settings, $pipes, null, $env);
if (is_resource($process)) {
    fwrite($pipes[0], $php_input);
    fclose($pipes[0]);
    $return_output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $return_code = proc_close($process);
}

if (!empty($return_output)) {
    list($response_headers, $response_body) = $response = preg_split("/\R\R/", $return_output, 2, PREG_SPLIT_NO_EMPTY);
    foreach (preg_split("/\R/", $response_headers) as $response_header) {
        header($response_header);
    }

    if (isset($request_headers["Accept-Encoding"]) && strpos($request_headers["Accept-Encoding"], "gzip") !== false && GZIP_SUPPORT) {
        $gzipoutput = gzencode($response_body, 6);
        ini_set("zlib.output_compression", "Off");
        header("Content-Encoding: gzip");
        header("Content-Length: " . strlen($gzipoutput));
        echo $gzipoutput;
    } else {
        echo $response_body;
    }
}

if (DEBUG_LOG) {
    $log = "";
    //$log .= "\$_GET = " . print_r($_GET, true);
    //$log .= "\$_POST = " . print_r($_POST, true);
    //$log .= "\$_SERVER = " . print_r($_SERVER, true);
    $log .= "\$request_headers = " . print_r($request_headers, true);
    $log .= "\$env = " . print_r($env, true);
    $log .= "\$php_input = " . PHP_EOL . $php_input . PHP_EOL;
    //$log .= "\$return_output = " . PHP_EOL . $return_output . PHP_EOL;
    $log .= "\$response = " . print_r($response, true);
    $log .= str_repeat("-", 80) . PHP_EOL;
    $log .= PHP_EOL;
    if (isset($_GET["service"]) && $_GET["service"] == "git-receive-pack")
        file_put_contents(LOG_RESPONSE, "");
    file_put_contents(LOG_RESPONSE, $log, FILE_APPEND);
}

class GitSmart {

    private $repoRoot;

    public function __construct($repoRoot) {
        $this->repoRoot = $repoRoot;
    }

    public function newRepo($repo) {

        $repoPath = $this->getRepoPath($repo);

        if (file_exists($repoPath)) {
            throw new Exception("Repo '" . $repo . "' already exists.");
        }

        mkdir($repoPath);
        $this->gitInit($repoPath);
        echo "<br><br>" . PHP_EOL;
    }

    public function deleteRepo($repo) {

        $repoPath = $this->getRepoPath($repo);

        if (!file_exists($repoPath)) {
            throw new Exception("Repo '" . $repo . "' not exists.");
        }

        system("rm -rf " . $repoPath, $return_code);

        if ($return_code != 0) {
            throw new Exception("Delete repo '" . $repo . "' failed (" . $return_code . ").");
        }
    }

    protected function getRepoPath($repo) {

        $repoPath = $this->repoRoot . "/" . $repo;

        if ($repoPath == $this->repoRoot) {
            throw new Exception("Invalid repo '" . $repo . "'.");
        }

        return $repoPath;
    }

    protected function gitInit($repoPath) {
        system(GIT_BIN . " init --bare " . $repoPath);
    }

}
