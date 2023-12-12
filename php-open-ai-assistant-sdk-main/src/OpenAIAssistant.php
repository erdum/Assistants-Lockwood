<?php

namespace Erdum;

class OpenAIAssistant {

    private $api_key;
    private $assistant_id;
    private $base_url;
    private $version_header;

    public $has_tool_calls = false;
    public $tool_call_id = null;

    public function __construct(
        $api_key,
        $assistant_id = null,
        $base_url = 'https://api.openai.com/v1',
        $version_header = 'OpenAI-Beta: assistants=v1'
    )
    {
        $this->api_key = $api_key;
        $this->assistant_id = $assistant_id;
        $this->base_url = $base_url;
        $this->version_header = $version_header;
    }

    public function create_assistant($name, $instructions, $tools)
    {
        $response = $this->send_post_request('/assistants', array(
            'name' => $name,
            'instructions' => $instructions,
            'model' => 'gpt-3.5-turbo-1106',
            'tools' => $tools
        ));

        if (empty($response['id'])) {
            throw new \Exception('Unable to create a assistant');
        }
        $this->assistant_id = $response['id'];
        return $response['id'];
    }

    public function modify_assistant($name, $instructions, $tools)
    {
        if (!$this->assistant_id) {
            throw new \Exception(
                'You need to provide a assistant_id or create an assistant.'
            );
        }

        $response = $this->send_post_request("/assistants/{$this->assistant_id}", array(
            'name' => $name,
            'instructions' => $instructions,
            'model' => 'gpt-3.5-turbo-1106',
            'tools' => $tools
        ));

        if (empty($response['id'])) {
            throw new \Exception('Unable to create a assistant');
        }
        $this->assistant_id = $response['id'];
        return $response['id'];
    }

    public function list_assistants()
    {
        $response = $this->send_get_request('/assistants');

        if (empty($response['data'])) {
            return array();
        }
        return $response['data'];
    }

    public function create_thread($content, $role = 'user')
    {
        $response = $this->send_post_request('/threads', array(
            'messages' => array(
                array(
                    'role' => $role,
                    'content' => $content
                )
            )
        ));

        if (empty($response['id'])) {
            throw new \Exception('Unable to create a thread');
        }
        return $response['id'];
    }

    public function get_thread($thread_id)
    {
        $response = $this->send_get_request("/threads/{$thread_id}");

        if (empty($response['id'])) {
            throw new \Exception('Unable to retrieve a thread');
        }
        return $response;
    }

    public function delete_thread($thread_id)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "{$this->base_url}/threads/{$thread_id}");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer {$this->api_key}",
            'Content-Type: application/json',
            'Accept: application/json',
            $this->version_header
        ));
        $response = $this->execute_request($ch);

        if (empty($response['deleted'])) {
            throw new \Exception('Unable to delete the thread');
        }
        return $response['id'];
    }

    public function add_message($thread_id, $content, $role = 'user')
    {
        // Check if any latest run requires_action
        // Before adding a new message to the thread
        $runs = $this->list_runs($thread_id);

        if (count($runs) > 0) {
            $last_run = $runs[0];

            if ($last_run['status'] == 'requires_action') {
                $this->has_tool_calls = true;
                $this->tool_call_id = $last_run['id'];
                return false;
            } else {
                $this->has_tool_calls = false;
                $this->tool_call_id = null;
            }
        }

        $response = $this->send_post_request(
            "/threads/{$thread_id}/messages",
            array(
                'role' => $role,
                'content' => $content
            )
        );

        if (empty($response['id'])) {
            throw new \Exception('Unable to create a message');
        }
        return $response['id'];
    }

    public function get_message($thread_id, $message_id)
    {
        $response = $this->send_get_request("/threads/{$thread_id}/messages/{$message_id}");

        if (empty($response['id'])) {
            throw new \Exception('Unable to retrive a message');
        }
        return $response;
    }

    public function list_thread_messages($thread_id)
    {
        $response = $this->send_get_request("/threads/{$thread_id}/messages");

        if (empty($response['data'])) {
            return array();
        }
        return $response['data'];
    }

    public function run_thread($thread_id)
    {
        // Check if any latest run requires_action
        // Before creating and running a new thread
        $runs = $this->list_runs($thread_id);

        if (count($runs) > 0) {
            $last_run = $runs[0];

            if ($last_run['status'] == 'requires_action') {
                $this->has_tool_calls = true;
                $this->tool_call_id = $last_run['id'];
                return false;
            } else {
                $this->has_tool_calls = false;
                $this->tool_call_id = null;
            }
        }

        $run_id = $this->create_run($thread_id, $this->assistant_id);

        do {
            sleep(5);
            $run = $this->get_run($thread_id, $run_id);
        } while (!(
            $run['status'] == 'completed'
            || $run['status'] == 'requires_action'
        ));

        if ($run['status'] == 'requires_action') {
            $this->has_tool_calls = true;
            $this->tool_call_id = $run['id'];
            return $run['id'];
        } else if ($run['status'] == 'completed') {
            return $run['id'];
        }
        return false;
    }

    public function execute_tools(
        $thread_id,
        $execution_id,
        $optional_object = null
    )
    {
        $run = $this->get_run($thread_id, $execution_id);
        $calls = $run['required_action']['submit_tool_outputs']['tool_calls'];
        $outputs = array();
        $log_entry = '';

        foreach ($calls as $call) {
            $method_name = $call['function']['name'];
            $method_args = json_decode($call['function']['arguments'], true);
            $callable = $optional_object ? 
                array($optional_object, $method_name) : $method_name;

            if (is_callable($callable)) {
                $data = call_user_func_array(
                    $callable,
                    $method_args
                );
                array_push($outputs, array(
                    'tool_call_id' => $call['id'],
                    'output' => json_encode($data)
                ));
                $log_entry .= "$method_name -> " . print_r($method_args, true);
            } else {
                throw new \Exception("Failed to execute tool: The $method_name you provided is not callable");
            }
        }
        $this->write_log($log_entry);
        $this->has_tool_calls = false;
        return $outputs;
    }

    public function submit_tool_outputs($thread_id, $execution_id, $outputs)
    {
        $response = $this->send_post_request(
            "/threads/{$thread_id}/runs/{$execution_id}/submit_tool_outputs",
            array('tool_outputs' => $outputs)
        );
        $this->write_log("outputs -> " . print_r($outputs, true));

        if (empty($response['id'])) {
            throw new \Exception('Unable to submit tool outputs');
        }

        do {
            sleep(5);
            $run = $this->get_run($thread_id, $response['id']);
        } while (!(
            $run['status'] == 'completed'
            || $run['status'] == 'requires_action'
        ));

        if ($run['status'] == 'requires_action') {
            $this->has_tool_calls = true;
            $this->tool_call_id = $run['id'];
            return $run['id'];
        } else if ($run['status'] == 'completed') {
            return $run['id'];
        }
        return false;
    }

    private function execute_request($ch)
    {
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($errno = curl_errno($ch)) {
            throw new \Exception(
                'CURL failed to call OpenAI API: ' . curl_error($ch),
                $errno
            );
        } else if ($http_code != 200) {
            throw new \Exception(
                "OpenAI API Returned Unexpected HTTP code $http_code. " . print_r($response, true)
            );
        }
        $response = json_decode($response, true);

        if ($response['last_error']) {
            throw new \Exception(
                "OpenAI API Returned error. " . 
                print_r($response['last_error'], true)
            );
        }
        curl_close($ch);
        return $response;
    }

    private function send_get_request($route)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "{$this->base_url}{$route}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer {$this->api_key}",
            'Content-Type: application/json',
            'Accept: application/json',
            $this->version_header
        ));
        return $this->execute_request($ch);
    }

    private function send_post_request($route, $payload = null)
    {
        $ch = curl_init();

        if (!empty($payload)) curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            json_encode($payload)
        );
        curl_setopt($ch, CURLOPT_URL, "{$this->base_url}{$route}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer {$this->api_key}",
            'Content-Type: application/json',
            'Accept: application/json',
            $this->version_header
        ));
        return $this->execute_request($ch);
    }

    private function create_run($thread_id, $assistant_id)
    {
        $response = $this->send_post_request(
            "/threads/{$thread_id}/runs",
            array('assistant_id' => $assistant_id)
        );

        if (empty($response['id'])) {
            throw new \Exception('Unable to create a run');
        }
        return $response['id'];
    }

    private function get_run($thread_id, $run_id)
    {
        $response = $this->send_get_request("/threads/{$thread_id}/runs/{$run_id}");

        if (empty($response['id'])) {
            throw new \Exception('Unable to create a run');
        }
        return $response;
    }

    private function list_runs($thread_id)
    {
        $response = $this->send_get_request("/threads/{$thread_id}/runs");

        if (empty($response['data'])) {
            return array();
        }
        return $response['data'];
    }

    private function write_log($message)
    {
        $logFile = __DIR__ . '/tool_calls_log';
        $logEntry = date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL;

        if ($fileHandle = fopen($logFile, 'a')) {
            fwrite($fileHandle, $logEntry);
            fclose($fileHandle);
            return true;
        }
        return false;
    }
}
