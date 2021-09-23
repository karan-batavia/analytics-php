<?php

declare(strict_types=1);

namespace Segment\Consumer;

class Socket extends QueueConsumer
{
    protected string $type = 'Socket';
    private bool $socket_failed = false;

    /**
     * Creates a new socket consumer for dispatching async requests immediately
     * @param string $secret
     * @param array $options
     *     number "timeout" - the timeout for connecting
     *     function "error_handler" - function called back on errors.
     *     bool "debug" - whether to use debug output, wait for response.
     */
    public function __construct(string $secret, array $options = [])
    {
        if (!isset($options['timeout'])) {
            $options['timeout'] = 5;
        }

        if (!isset($options['host'])) {
            $options['host'] = 'api.segment.io';
        }

        parent::__construct($secret, $options);
    }

    public function flushBatch($batch): bool
    {
        $socket = $this->createSocket();

        if (!$socket) {
            return false;
        }

        $payload = $this->payload($batch);
        $payload = json_encode($payload);

        $body = $this->createBody($this->options['host'], $payload);
        if ($body === false) {
            return false;
        }

        return $this->makeRequest($socket, $body);
    }

    /**
     * @return false|resource
     */
    private function createSocket()
    {
        if ($this->socket_failed) {
            return false;
        }

        $protocol = $this->ssl() ? 'ssl' : 'tcp';
        $host = $this->options['host'];
        $port = $this->ssl() ? 443 : 80;
        $timeout = $this->options['timeout'];

        try {
            // Open our socket to the API Server.
            // Since we're try catch'ing prevent PHP logs.
            $socket = @pfsockopen(
                $protocol . '://' . $host,
                $port,
                $errno,
                $errstr,
                $timeout
            );

            // If we couldn't open the socket, handle the error.
            if ($socket === false) {
                $this->handleError($errno, $errstr);
                $this->socket_failed = true;

                return false;
            }

            return $socket;
        } catch (\Exception $e) {
            $this->handleError($e->getCode(), $e->getMessage());
            $this->socket_failed = true;

            return false;
        }
    }

    /**
     * Create the body to send as the post request.
     * @param string $host
     * @param string $content
     * @return string body
     */
    private function createBody(string $host, string $content)
    {
        $req = "POST /v1/batch HTTP/1.1\r\n";
        $req .= 'Host: ' . $host . "\r\n";
        $req .= "Content-Type: application/json\r\n";
        $req .= 'Authorization: Basic ' . base64_encode($this->secret . ':') . "\r\n";
        $req .= "Accept: application/json\r\n";

        // Send user agent in the form of {library_name}/{library_version} as per RFC 7231.
        $content_json = json_decode($content, true);
        $library = $content_json['batch'][0]['context']['library'];
        $libName = $library['name'];
        $libVersion = $library['version'];
        $req .= "User-Agent: $libName/$libVersion\r\n";

        // Compress content if compress_request is true
        if ($this->compress_request) {
            $content = gzencode($content);

            $req .= "Content-Encoding: gzip\r\n";
        }

        $req .= 'Content-length: ' . strlen($content) . "\r\n";
        $req .= "\r\n";
        $req .= $content;

        // Verify message size is below than 32KB
        if (strlen($req) >= 32 * 1024) {
            $msg = 'Message size is larger than 32KB';
            error_log('[Analytics][' . $this->type . '] ' . $msg);

            return false;
        }

        return $req;
    }

    /**
     * Attempt to write the request to the socket, wait for response if debug
     * mode is enabled.
     * @param resource $socket the handle for the socket
     * @param string $req request body
     * @param bool $retry
     * @return bool
     */
    private function makeRequest($socket, string $req, bool $retry = true): bool
    {
        $bytes_written = 0;
        $bytes_total = strlen($req);
        $closed = false;
        $success = true;

        // Retries with exponential backoff until success
        $backoff = 100; // Set initial waiting time to 100ms

        while (true) {
            // Send request to server
            while (!$closed && $bytes_written < $bytes_total) {
                try {
                    // Since we're try catch'ing prevent PHP logs.
                    $written = @fwrite($socket, substr($req, $bytes_written));
                } catch (\Exception $e) {
                    $this->handleError($e->getCode(), $e->getMessage());
                    $closed = true;
                }
                if (!isset($written) || !$written) {
                    $closed = true;
                } else {
                    $bytes_written += $written;
                }
            }

            // Get response for request
            $statusCode = 0;

            if (!$closed) {
                $res = $this->parseResponse(fread($socket, 2048));
                $statusCode = (int)$res['status'];
            }
            fclose($socket);

            // If status code is 200, return true
            if ($statusCode === 200) {
                return true;
            }

            // If status code is greater than 500 and less than 600, it indicates server error
            // Error code 429 indicates rate limited.
            // Retry uploading in these cases.
            if (($statusCode >= 500 && $statusCode <= 600) || $statusCode === 429 || $statusCode === 0) {
                if ($backoff >= $this->maximum_backoff_duration) {
                    break;
                }

                usleep($backoff * 1000);
            } elseif ($statusCode >= 400) {
                if ($this->debug()) {
                    $this->handleError($res['status'], $res['message']);
                }

                break;
            }

            // Retry uploading...
            $backoff *= 2;
            $socket = $this->createSocket();
        }

        return $success;
    }

    /**
     * Parse our response from the server, check header and body.
     * @param string $res
     * @return array
     *     string $status HTTP code, e.g. "200"
     *     string $message JSON response from the api
     */
    private function parseResponse(string $res): array
    {
        $contents = explode("\n", $res);

        // Response comes back as HTTP/1.1 200 OK
        // Final line contains HTTP response.
        $status = explode(' ', $contents[0], 3);
        $result = $contents[count($contents) - 1];

        return [
            'status'  => $status[1] ?? null,
            'message' => $result,
        ];
    }
}
