<?php

	class Server_Socket
	{
		private $ip_address;
		private $port;
		private $stream_select_timeout;
		private $is_running;

		private $socket;
		private $clients;
		private $incoming_message_queue;
		private $outgoing_message_queue;


		function __construct()
		{
			$this->ip_address = "0.0.0.0";
			$this->port = 666;
			$this->stream_select_timeout = 2;
			$this->is_running = $this->Create();

			$this->clients = array();
			$this->incoming_message_queue = array();
			$this->outgoing_message_queue = array();
		}

		public function Set_IP_Address($ip_address){ $this->ip_address = $ip_address; }
		public function Set_Port($port){ $this->port = $port; }
		public function Set_Stream_Select_Timeout($stream_select_timeout){ $this->stream_select_timeout = $stream_select_timeout; }

		public function Is_Running(){ return $this->is_running; }
		public function Get_Pending_Messages()
		{
			$message_count = count($this->incoming_message_queue);

			if($message_count > 0)
			{
				$messages_to_return = array();

				for($i = 0; $i < $message_count; $i++)
				{
					$messages_to_return[] = $this->incoming_message_queue[$i];

					$this->incoming_message_queue[$i] = null;
				}

				for($i = ($message_count - 1); $i > -1 ; $i--) if(empty($this->incoming_message_queue[$i])) unset($this->incoming_message_queue[$i]);

				$this->incoming_message_queue = array_values($this->incoming_message_queue);

				return $messages_to_return;
			}
			else return false;
		}

		private function Create()
		{
			if(!($this->socket = stream_socket_server("tcp://{$this->ip_address}:{$this->port}", $error_code, $error_msg)))
			{
				echo "Could NOT Create Server Socket:\n";
				echo "-------------------------------\n";
				echo "    Error Code:    {$error_code}\n";
				echo "    Error Message: {$error_msg}\n\n";

				return false;
			}
			else return true;
		}

		private function New_Clients_Pending()
		{
			$server = array($this->socket);

			if(stream_select($server, $write, $except, $this->stream_select_timeout)) return true; else return false;
		}

		private function Connect_Clients()
		{
			while($this->New_Clients_Pending())
			{
				$new_client = stream_socket_accept($this->socket);

				if($new_client) $this->clients[] = array("socket" => $new_client, "socket_id" => null);
			}
		}

		public function Close_Client_Connection($client, $index)
		{
			$connection_closed = false;

			if(!isset($index)) $index = $this->Find_Client_Index($client);

			if($index != false || $index == 0)
			{
				fclose($client);
				$connection_closed = true;

				unset($this->clients[$index]);
				$this->clients = array_values($this->clients);
			}

			return $connection_closed;
		}

		private function Find_Client_Index($client)
		{
			$client_name = stream_socket_get_name($client, true);

			$client_not_found = true;
			$index = -1;

			for($i = 0; $i < count($this->clients) && $client_not_found; $i++)
			{
				$i_client_name = stream_socket_get_name($this->clients[$i]["socket"], true);

				if($client_name == $i_client_name)
				{
					$client_not_found = false;
					$index = $i;
				}
			}

			if(!$client_not_found) return $index; else return false;
		}

		private function Find_Client($socket_id)
		{
			$client_not_found = true;
			$client = false;
			$clients_count = count($this->clients);

			for($i = 0; $i < $clients_count && $client_not_found; $i++)
			{
				if($this->clients[$i]["socket_id"] == $socket_id)
				{
					$client_not_found = false;
					$client = $this->clients[$i];
				}
			}

			return $client;
		}

		public function Queue_Outgoing_Message($socket_id, $message)
		{
			$this->outgoing_message_queue[] = array("socket_id" => $socket_id, "message" => $message);
		}

		private function Send($client, $message)
		{
			if(fwrite($client,  base64_encode(serialize($message)) . "\n")) return true; else return false;
		}

		private function Get_Sendable_Clients()
		{
			if(count($this->clients) > 0)
			{
				$sendable_clients = array();

				for($i = 0; $i < count($this->clients); $i++) $sendable_clients[] = $this->clients[$i]["socket"];

				if(stream_select($read, $sendable_clients, $except, $this->stream_select_timeout)) return array_values($sendable_clients);
				else return false;
			}
			else return false;
		}

		private function Process_Outgoing_Messages()
		{
			$outgoing_message_count = count($this->outgoing_message_queue);

			if($outgoing_message_count > 0)
			{
				$sendable_clients = $this->Get_Sendable_Clients();

				for($i = 0; $i < count($sendable_clients); $i++)
				{
					$client_index = $this->Find_Client_Index($sendable_clients[$i]);

					if($client_index != false || $client_index == 0)
					{
						$outgoing_message_count = count($this->outgoing_message_queue);

						for($j = 0; $j < $outgoing_message_count; $j++)
							if($this->clients[$client_index]["socket_id"] == $this->outgoing_message_queue[$j]["socket_id"])
								if($this->Send($this->clients[$client_index]["socket"], $this->outgoing_message_queue[$j]["message"]))
									$this->outgoing_message_queue[$j] = null;

						for($j = ($outgoing_message_count - 1); $j > -1; $j--)
							if(empty($this->outgoing_message_queue[$j])) unset($this->outgoing_message_queue[$j]);

						$this->outgoing_message_queue = array_values($this->outgoing_message_queue);
					}
				}
			}
		}

		private function Get_Receivable_Clients()
		{
			if(count($this->clients) > 0)
			{
				$receivable_clients = array();

				for($i = 0; $i < count($this->clients); $i++) $receivable_clients[] = $this->clients[$i]["socket"];

				if(stream_select($receivable_clients, $write, $except, $this->stream_select_timeout)) return array_values($receivable_clients);
				else return false;
			}
			else return false;
		}

		private function Receive($client)
		{
			$input = unserialize(base64_decode(fgets($client)));

			if($input) return $input; else return false;
		}

		private function Queue_Incoming_Messages()
		{
			$receivable_clients = $this->Get_Receivable_Clients();

			for($i = 0; $this->is_running && $receivable_clients && $i < count($receivable_clients); $i++)
			{
				$message = null;

				if($receivable_clients[$i]) $message = $this->Receive($receivable_clients[$i]);

				if($message)
				{
					if($message["action"] == "Identify_Socket")
					{
						$client_not_found = true;
						$client_name_i = stream_socket_get_name($receivable_clients[$i], true);
						$client_index = -1;

						for($j = 0; $j < count($this->clients) && $client_not_found; $j++)
						{
							$client_name_j = stream_socket_get_name($this->clients[$j]["socket"], true);

							if($client_name_i == $client_name_j)
							{
								$client_index = $j;
								$client_not_found = false;
							}
						}

						if(!$client_not_found) $this->clients[$client_index]["socket_id"] = $message["socket_id"];

						$clients_to_read[$i]["socket_id"] = $message["socket_id"];
					}

					$this->incoming_message_queue[] = $message;
				}
			}
		}

		public function Process_Requests()
		{
			if($this->is_running)
			{
				$this->Connect_Clients();
				$this->Process_Outgoing_Messages();
				$this->Queue_Incoming_Messages();
			}
		}

		public function Kill()
		{
			$this->is_running = false;

			fclose($this->socket);

			unset($this->clients);
			unset($this->incoming_message_queue);
			unset($this->outgoing_message_queue);
		}
	}




