<?php

	class Client_Socket
	{
		private $socket;

		private $ip_address;
		private $port;
		private $socket_id;
		private $incoming_message_queue;
		private $outgoing_message_queue;

		private $is_connected;
		private $stream_select_timeout;

		private $debug_file_stream;
		private $debug;

		function __construct()
		{
			$this->ip_address = "localhost";
			$this->port = 666;
			$this->incoming_message_queue = array();
			$this->outgoing_message_queue = array();

			$this->debug = true;

			$this->stream_select_timeout = 2;
			$this->is_connected = $this->Create();
		}

		public function Set_Debug_File_Stream($debug_file_stream){ $this->debug_file_stream = $debug_file_stream; $this->debug = true; }

		public function Set_IP_Address($ip_address){ $this->ip_address = $ip_address; }
		public function Set_Port($port){ $this->port = $port; }
		public function Set_Stream_Select_Timeout($stream_select_timeout){ $this->stream_select_timeout = $stream_select_timeout; }

		public function Is_Connected(){ return $this->is_connected; }
		public function Get_Pending_Message()
		{
			if(count($this->incoming_message_queue) > 0)
			{
				$message = $this->incoming_message_queue[0];

				unset($this->incoming_message_queue[0]);

				$this->incoming_message_queue = array_values($this->incoming_message_queue);

				return $message;
			}
			else return false;
		}

		private function Create()
		{
			if(!($this->socket = stream_socket_client("tcp://{$this->ip_address}:{$this->port}", $error_code, $error_msg)))
			{
				$error_code = socket_last_error();
				$error_msg  = socket_strerror($error_code);

				$str  = "CS: Could NOT Create Client Socket:\r\n";
				$str .= "CS: -------------------------------\r\n";
				$str .= "CS:     Error Code:    {$error_code}\r\n";
				$str .= "CS:     Error Message: {$error_msg}\r\n\r\n";
				fwrite($this->debug_file_stream, $str);

				return false;
			}
			else return true;
		}

		public function Queue_Outgoing_Message($message)
		{
			if($this->debug)
			{
				$str = "CS: Queue_Outgoing_Message:\r\n";
				$str .= print_r($message, true);
				$str .= "\r\n";
				fwrite($this->debug_file_stream, $str);
			}

			$message = base64_encode(serialize($message)) . "\n";

			$this->outgoing_message_queue[] = $message;
		}

		private function Process_Outgoing_Messages()
		{
			if($this->debug)
			{
				$str = "CS: Process_Outgoing_Messages:\r\n";
				fwrite($this->debug_file_stream, $str);
			}

			while(isset($this->outgoing_message_queue[0]))
			{
				if($this->debug)
				{
					$str = "    Message is in queue.\r\n";
					fwrite($this->debug_file_stream, $str);
				}

				if($this->Send_Not_Blocked())
				{
					if($this->debug)
					{
						$str = "    Send is not blocked.\r\n";
						fwrite($this->debug_file_stream, $str);
					}

					if($this->Send($this->outgoing_message_queue[0]))
					{
						if($this->debug)
						{
							$str = "    Message has been sent.\r\n";
							fwrite($this->debug_file_stream, $str);
						}

						unset($this->outgoing_message_queue[0]);

						$this->outgoing_message_queue = array_values($this->outgoing_message_queue);
					}
				}
			}
		}

		private function Send($message)
		{
			if($this->debug)
			{
				$str = "CS: Send:\r\n";
				$str .= print_r($message, true);
				$str .= "\r\n";
				fwrite($this->debug_file_stream, $str);
			}

			if(fwrite($this->socket, $message)) return true; else return false;
		}

		private function Send_Not_Blocked()
		{
			if($this->is_connected)
			{
				$check_socket = array($this->socket);

				if(stream_select($read, $check_socket, $except, $this->stream_select_timeout)) return true;
				else return false;
			}
			else return false;
		}

		private function Queue_Incoming_Messages()
		{
			while($this->Message_Pending()) $this->incoming_message_queue[] = unserialize(base64_decode($this->Receive()));
		}

		private function Receive()
		{
			$input = fgets($this->socket);

			if($input) return $input; else return false;
		}

		private function Message_Pending()
		{
			if($this->is_connected)
			{
				$check_socket = array($this->socket);

				if(stream_select($check_socket, $write, $except, $this->stream_select_timeout)) return true;
				else return false;
			}
			else return false;
		}

		public function Process_Requests()
		{
			$this->Process_Outgoing_Messages();
			$this->Queue_Incoming_Messages();
		}

		public function Kill()
		{
			$this->is_connected = false;

			fclose($this->socket);

			unset($this->socket_id);
			unset($this->incoming_message_queue);
			unset($this->outgoing_message_queue);
		}
	}
