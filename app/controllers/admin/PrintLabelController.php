<?php namespace Controllers\Admin;

use AdminController;
use Input;
use Asset;
use PrintLabel;
use Setting;
use Redirect;
use Response;
use Company;
use View;

class PrintLabelController extends AdminController
{

	function __construct() {
		$this->settings = Setting::getSettings();
	}

	/**
	*  Handler for labels printed from bulk edit view.
	* 
	* @return View
	**/
	public function processLabelsFromBulkEdit($assets = null)
	{
		$asset_ids = [];

		if (!Company::isCurrentUserAuthorized()) {
			return Redirect::to('hardware')->with('error', Lang::get('general.insufficient_permissions'));
		} elseif (!Input::has('edit_asset')) {
			return Redirect::back()->with('error', 'No assets selected');
		} else {
			$raw_assets = Input::get('edit_asset');
			$asset_ids = $this->getAssetIdsFromBulkEdit($raw_assets);
		}

		if ($this->settings->qr_code=='1') {
			if($this->settings->barcode_type === 'ZPL') {
				return $this->outputAssetLabelsToZPL($asset_ids, 'bulk');
			} else {
				return $this->outputAssetLabelsToAvery($asset_ids);
			}
		} else {
			// Barcode labels not enabled
			return Redirect::to("hardware")->with('error','Barcodes are not enabled in Admin > Settings');
		}
	}

	/**
	*  Handle printing of a single label
	*
	* @param int $asset_id
	* @return json
	**/
	public function printSingleLabel($asset_id = null)
	{
	}

	/**
	*  Takes raw array from the bulk edit form and returns asset IDs.
	* 
	* @param array $asset_raw_array
	* @return array
	**/
	private function getAssetIdsFromBulkEdit($asset_raw_array = array())
	{
		$asset_ids = [];
		foreach ($asset_raw_array as $asset_id => $value) {
			$asset_ids[] = $asset_id;
		}
		return $asset_ids;
	}

	/**
	*  Generates a printable page compatible with several standard
	*  avery brand label sheets.
	*
	* @param array $asset_ids
	* @return View
	**/
	public function outputAssetLabelsToAvery($asset_ids = null)
	{
		if($asset_ids === null) {
			return Redirect::back()->with('error', 'No assets selected');
		}
		$assets = Asset::find($asset_ids);
		$assetcount = count($assets);
		$count = 0;
		return View::make('backend/hardware/labels')->with('assets',$assets)->with('settings',$this->settings)->with('count',$count);
	}

	/**
	*  Connects to ZPL capable network printer
	*  and generates ZPL markup for printing labels.
	*
	* @param array $asset_ids
	* @return Redirect
	**/
	public function outputAssetLabelsToZPL($asset_ids = null, $mode = 'ajax')
	{
		$verbose = false;
		$redirect = false;
		$status = 'OK';
		$statusMessage = 'Labels sent to printer';
		$printerIp = '';
		$printerPort = '9100'; // Default port for raw connections to ZPL printers
		$labelFormat = '2.25x1.25';
		$labelTemplate = $this->settings->zpl_template;

		if($mode === 'bulk') {
			$verbose = true;
			$redirect = true;
		}

		// Get ready for lots of ugly!
		if($asset_ids === null) {
			return Redirect::back()->with('error', 'No assets selected');
		}
		$assets = Asset::find($asset_ids);

		$printer = $this->settings->zpl_printer;
		if($printer!='') {
			if(strpos($printer, ':') === false) {
				$printerIp = $printer;
			} else {
				list($printerIp, $printerPort) = explode(':', $printer);
			}
		} else {
			return Redirect::to("hardware")->with('error','ZPL printer address not configured');
		}
		
		if($labelTemplate=='') {
			return Redirect::to("hardware")->with('error','No ZPL template configured');
		}
		if($verbose) {
			echo "Attempting to create socket...";
		}
		$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if ($socket === false) {
			$status = "ERROR";
			$statusMessage = "socket_create() failed: reason: " . socket_strerror(socket_last_error());
			if($verbose) {
				echo $statusMessage."<br>";
			}
		} else {
			if($verbose) {
				echo "OK.<br>\n";
			}
		}

		if($verbose) {
			echo "Attempting to connect to '$printerIp' on port '$printerPort'...";
		}
		$result = socket_connect($socket, $printerIp, $printerPort);
		if ($result === false) {
			$status = "ERROR";
			$statusMessage = "socket_connect() failed.\nReason: ($result) " . socket_strerror(socket_last_error($socket));
			if($verbose) {
				echo $statusMessage."<br>";
			}
		} else {
			if($verbose) {
				echo "OK.<br>\n";
			}
		}

		if($verbose) {
			echo "Loading label format...";
		}

// This should go into settings as a textarea to let the end-user write their own label markup.
// Optionally maybe have a couple label formats for people to start from
		socket_write($socket, <<<FORMAT
^XA
^DFR:$labelFormat.GRF^FS
$labelTemplate
^XZ
FORMAT
);
		if($verbose) {
			echo "OK.<br><br>";
		}


		if($verbose) {
			echo "Printing labels...<br>\n";
		}
		foreach($assets as $asset) {

			if($verbose) {
				echo "Printing $asset->asset_tag<br>";
			}

			socket_write($socket, <<<LABEL_START
^XA
^XFR:$labelFormat.GRF^FS
LABEL_START
);

			// QR Code
			socket_write($socket, '^FN1^FDQA,'.route('view/hardware', $asset->id).'^FS');

			// Label Header
			if($this->settings->qr_text!='') {
				socket_write($socket, '^FN3^FD'.$this->settings->qr_text.'^FS');
			}

			// Asset Name
			if($asset->name!='') {
				socket_write($socket, '^FN4^FDN: '.$asset->name.'^FS');
			} else {
				socket_write($socket, '^FN4^FDN: '.html_entity_decode($asset->model->manufacturer->name).' '.html_entity_decode($asset->model->modelno).'^FS');
			}

			// Asset Model
			if($asset->model!='') {
				socket_write($socket, '^FN5^FDM: '.html_entity_decode($asset->model->modelno).' '.html_entity_decode($asset->model->name).'^FS');
			}

			// Asset Tag
			if($asset->asset_tag!='') {
				socket_write($socket, '^FN7^FDT: '.$asset->asset_tag.'^FS');
				socket_write($socket, '^FN2^FD'.$asset->asset_tag.'^FS');
			}

			// Asset Serial
			if($asset->serial!='') {
				socket_write($socket, '^FN6^FDS: '.$asset->serial.'^FS');
			}

// Comment to disable actual printing.
			socket_write($socket, '^XZ');

		}

		if($verbose) {
			echo "<br>Closing socket...";
		}
		socket_close($socket);
		if($verbose) {
			echo "OK.<br><br>";
		}

		if($redirect === true && $status === 'OK') {
			return Redirect::to("hardware")->with('success','Labels sent to printer');
		} elseif($redirect === true && $status !== 'OK') {
			return Redirect::to("hardware")->with('error',$statusMessage);
		}
		if($mode === 'ajax') {
			return Response::json(['status' => $status, 'message' => $statusMessage]);
		}

	}
}
