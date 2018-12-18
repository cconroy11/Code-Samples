<?php
//CBB-270.1
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Construction\ConstructionProject;
use App\Models\Vendor;
use App\Models\Construction\ConstructionProjectVendor;
use App\Models\Construction\ConstructionProjectPhase;
use App\Models\Construction\ConstructionProjectNote;
use App\Models\Construction\WorkOrderInvoice;
use App\Events\Construction\ConstructionProjectNoteCreated;
use App\Notifications\AssignedToProjectPhase;
use App\Notifications\ProjectPhaseUpdated;
use App\Exceptions\ApiException;
use App\Models\Superintendent;
use Excel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use App\Events\Construction\ConstructionProjectPhaseCompleted;

class ConstructionProjectApiController extends Controller
{

	/**
	 * API endpoint to fetch current vendors assigned to a certain phase
	 * @param  Request $request
	 * @return array
	 */
	public function fetchInvoices(Request $request)
	{
		$this->validate(request(), [
			'phase' => 'required'
		]);
		$invoices = ConstructionProjectVendor::with(['vendor' => function ($q) {
			$q->with('trades');
		}])
			->where('construction_project_phase_id', '=', $request->phase)
			->get();
		
		return $invoices;
	}

	/**
	 * API endpoint to update an invoice
	 * @param  Request $request
	 * @return array
	 */
	public function updateInvoice(Request $request)
	{
		$this->validate(request(), [
			'invoice' => 'required'
		]);
		$invoice = ConstructionProjectVendor::find($request->invoice['id']);
		$invoiceAmount = !empty($invoiceAmount = $request->invoice['amount']) ? $invoiceAmount : 0;
		$invoiceAmount = \DollarAmount::removeFormat($invoiceAmount);
		
		$invoice->update([
			'amount' => $invoiceAmount,
			'material' => $request->invoice['material'],
			'quantity' => $request->invoice['quantity'],
			'paid' => $request->invoice['paid'],
			'message' => $request->invoice['message'],
		]);
		return $invoice;
	}

	/**
	 * API endpoint to create an invoice
	 * @param  Request $request
	 * @return array
	 */
	public function createInvoice(Request $request)
	{
		if (empty($request->invoice)) {
			throw new ApiException('Invoice was not provided.');
		}

		$invoice = ConstructionProjectVendor::create($request->invoice);

		if (! $invoice) {
			throw new ApiException('Invoice was not created.');
		}

		$invoice = ConstructionProjectVendor::with(['vendor' => function ($q) {
			$q->with('trades');
		}])->where('id', '=', $invoice->id)->first();

		return ['status' => 'success', 'invoice' => $invoice];
	}

	/**
	 * API endpoint to create an invoice
	 * @param  Request $request
	 * @return array
	 */
	public function deleteInvoice(Request $request)
	{
		$this->validate(request(), [
			'invoice' => 'required'
		]);
		$invoice = ConstructionProjectVendor::find($request->invoice['id']);
		$invoice->delete();
	}

	/**
	 * API endpoint to create a project phase
	 * @param  Request $request
	 * @return ConstructionProjectPhase
	 */
	public function fetchPhases(Request $request)
	{
		$this->validate(request(), [
			'project' => 'required'
		]);

		$phases = ConstructionProjectPhase::where('construction_project_id', $request->project)->with('invoices')->with('user')->orderBy('rank')->get();

		foreach ($phases as $phase) {
			if ($phase->purchaseOrders->isEmpty()) {
				$phase->purchaseOrders()->create([]);
			}
		}
		return $phases->load('purchaseOrders');
	}

	/**
	 * API endpoint to create a project phase
	 * @param  Request $request
	 * @return ConstructionProjectPhase
	 */
	public function createPhase(Request $request)
	{
		$this->validate(request(), [
			'phase' => 'required'
		]);
		$phase = ConstructionProjectPhase::create([
			'construction_project_id' => $request->phase['construction_project_id'],
			'user_id' => $request->phase['user_id'],
			'name' => $request->phase['data']['name'],
			'category' => $request->phase['data']['category'],
			'has_labor' => $request->phase['data']['has_labor'],
			'has_material' => $request->phase['data']['has_material'],
			'has_quantity' => $request->phase['data']['has_quantity'],
			'rank' => $request->phase['rank'],
		])->load(['invoices', 'user']);
		
		
		//App/Helpers
		//@section, @column_name
		$users = \AdminNotifications::get('construction', 'c_project_phase_created');
	   
		//Users that want to get notified
		
		if (!empty($users)) {
			foreach ($users as $user) {
				$sendTo = User::where('email', $user)->first();
				//if either there is no user assigned to the project or
				//no notification set then skip with 'continue'
				if (empty($phase->user_id) || empty($sendTo->id)) {
					continue;
				}
				//if all OK then match the user
				if ($phase->user_id == $sendTo->id) {
					$sendTo->notify(new AssignedToProjectPhase($phase));
					\LogInfo::write('Message sent to '. $user, 'c_project_phase_created');
				}
			}
		}
		
		foreach ($request->phase['purchase_orders'] as $po) {
			$phase->purchaseOrders()->create([
				'order_number'       => $po['order_number'],
				'sales_order_number' => $po['sales_order_number'],
				'quantity'           => $po['quantity'],
				'amount'             => $po['amount'],
			]);
		}

		return $phase->load('purchaseOrders');
	}

	/**
	 * API endpoint to create a project phase
	 * @param  Request $request
	 * @return void
	 */
	public function updatePhaseRanks(Request $request)
	{
		$this->validate(request(), [
			'phases' => 'required'
		]);
		foreach ($request->phases as $key => $phase) {
			$phase = ConstructionProjectPhase::find($phase['id']);
			$phase->rank = $key;
			$phase->save();
		}
	}

	/**
	 * API endpoint to create a project phase
	 * @param  Request $request
	 * @return void
	 */
	public function deletePhase(Request $request)
	{
		$this->validate(request(), [
			'phase' => 'required'
		]);
		$phase = ConstructionProjectPhase::find($request->phase['id']);
		$phase->delete();
	}

  
	/**
	 * API endpoint to retrieve a list Home Builders
	 * to Filter Construction Projects
	 *
	 * @param  Request $request
	 * @return Illuminate\Support\Facades\Response
	 */
	public function fetchHomebuilderCurrentPhases(Request $request)
	{
		$phases = ConstructionProjectPhase::whereHas('constructionProject', function ($query) {
				$query->where('homebuilder_id', '=', request()->homebuilder);
		})
			->whereNull('completed_at')
			->whereRaw('rank = (select min(rank) from construction_project_phases as p2 where p2.construction_project_id = construction_project_phases.construction_project_id)')
			->select('name', 'id')
			->get();

		return $phases->unique('name');
	}

	public function fetchCurrentPhases(Request $request)
	{
		$phases = ConstructionProjectPhase::has('constructionProject')
			->whereNull('completed_at')
			->whereRaw('rank = (select min(rank) from construction_project_phases as p2 where p2.construction_project_id = construction_project_phases.construction_project_id)')
			->select('name', 'id')
			->get();

		return $phases->unique('name');
	}

	/**
	 * API endpoint to update a current project phase
	 * returns updated data
	 *
	 * @param  Request $request
	 * @return Illuminate\Support\Facades\Response
	 */
	public function updatePhase(Request $request)
	{
		$this->validate(request(), [
			'phase' => 'required'
		]);

		if (!isset($request->phase['id'])) {
			return response()->json(['message' => 'Missing Phase ID'], 400);
		}

		$phase = ConstructionProjectPhase::find($request->phase['id']);
		
		if (is_null($phase)) {
			return response()->json(['message' => 'Phase not Found'], 404);
		}

		$phase->update([
			'user_id'      => $request->phase['user_id'],
			'has_labor'    => $request->phase['has_labor'],
			'has_material' => $request->phase['has_material'],
			'has_quantity' => $request->phase['has_quantity'],
		]);

		$pos = \App\Models\Construction\PurchaseOrder::where('construction_project_phase_id', '=', $request->phase['id'])->delete();

		foreach ($request->phase['purchase_orders'] as $po) {
			$phase->purchaseOrders()->create([
				'order_number'       => $po['order_number'],
				'quantity'           => $po['quantity'],
				'amount'             => $po['amount'],
				'sales_order_number' => $po['sales_order_number']
			]);
		}
		
		//App/Helpers
		//@section, @column_name
		$users = \AdminNotifications::get('construction', 'c_project_phase_updated');
		
		if (!empty($users)) {
			foreach ($users as $user) {
				$sendTo = User::where('email', $user)->first();
				
				//if either there is no user assigned to the project or
				//no notification set then skip with 'continue'
				if (empty($phase->user_id) || empty($sendTo->id)) {
					continue;
				}
				//if all OK then match the user
				if ($phase->user_id === $sendTo->id) {
					$sendTo->notify(new ProjectPhaseUpdated($phase));
					//App\Helpers
					//log the event
					\LogInfo::write('Message sent to '. $user, 'c_project_phase_updated');
				}
			}
		}
		
		return $phase->load(['invoices', 'user', 'purchaseOrders']);
	}

	/**
	 * API endpoint to fetch project assets
	 *
	 * @param  Request $request
	 * @return ConstructionProject
	 */
	public function fetchProjectAssets(Request $request)
	{
		$this->validate(request(), [
			'project' => 'required'
		]);
		$assets = ConstructionProject::find($request->project)->getProjectAssets();
		array_map(function ($obj) {
			return $obj->s3 = Storage::disk('s3')->url($obj->url);
		}, $assets);
		return $assets;
	}

	/**
	 * API endpoint to fetch material takeoff
	 *
	 * @param  Request $request
	 * @return \Illuminate\Support\Collection Collection of ConstructionProject
	 */
	public function fetchMaterialTakeoff(Request $request)
	{
		$this->validate(request(), [
			'project' => 'required'
		]);
		$assets = ConstructionProject::find($request->project)->getMaterialTakeoff();
		array_map(function ($obj) {
			return $obj->s3 = Storage::disk('s3')->url($obj->url);
		}, $assets);
		return $assets;
	}

	/**
	 * API endpoint to delete material takeoff
	 *
	 * @param  Request $request
	 * @return void
	 */
	public function deleteMaterialTakeoff(Request $request)
	{
		$this->validate(request(), [
			'asset' => 'required',
			'project' => 'required',
		]);
		$project = ConstructionProject::find($request->project);
		$del = Storage::disk('s3')->delete($request->asset['url']);
		$project->removeMaterialTakeoff($request->asset['url']);
		$project->save();
	}

	/**
	 * API endpoint to delete a project asset
	 *
	 * @param  Request $request
	 * @return void
	 */
	public function deleteProjectAsset(Request $request)
	{
		$this->validate(request(), [
			'asset' => 'required',
			'project' => 'required',
		]);
		$project = ConstructionProject::find($request->project);
		$del = Storage::disk('s3')->delete($request->asset['url']);
		$project->removeProjectAsset($request->asset['url']);
		$project->save();
	}

	/**
	 * API endpoint to get construction project notes
	 *
	 * @param  Request $request
	 * @return \Illuminate\Support\Collection  Collection of ConstructionProjectNote
	 */
	public function fetchNotes(Request $request)
	{
		$this->validate(request(), [
			'project' => 'required',
		]);
		$notes = ConstructionProjectNote::with('user')
			->where('construction_project_id', '=', $request->project)
			->orderBy('created_at', 'DESC')
			->get();
		return $notes;
	}

	/**
	 * API endpoint to create a construction project note
	 *
	 * @param  Request $request
	 * @return ConstructionProjectNote
	 */
	public function createNote(Request $request)
	{
		$this->validate(request(), [
			'note' => 'required',
		]);

		$note    = ConstructionProjectNote::create($request->note)->load(['user']);
		$project = ConstructionProject::find($note->construction_project_id);

		event(new ConstructionProjectNoteCreated($project, $note));

		return $note;
	}

	/**
	 * API endpoint to update a construction project note
	 *
	 * @param  Request $request
	 * @return void
	 */
	public function updateNote(Request $request)
	{
		$this->validate(request(), [
			'note' => 'required',
		]);
		$note = ConstructionProjectNote::find($request->note['id'])
			->update([
				'content' => $request->note['content'],
				'updated_at' => Carbon::now()
			]);
	}

	/**
	 * API endpoint to update a construction project note
	 *
	 * @param  Request $request
	 * @return void
	 */
	public function deleteNote(Request $request)
	{
		$this->validate(request(), [
			'note' => 'required',
		]);
		$note = ConstructionProjectNote::find($request->note['id'])->delete();
	}

	/**
	 * API endpoint to mark a phase as complete (if it can)
	 *
	 * @param Illuminate\Http\Request $request
	 * @return Illuminate\Support\Facades\Response
	 */
	public function completePhase(Request $request)
	{
		$this->validate(request(), [
			'phase' => 'required',
		]);
		$response = ['messages' => [], 'status' => 'fail'];
		$phase    = ConstructionProjectPhase::find($request->phase['id']);

		if ($phase->constructionProject->on_hold) {
			$response['messages'][] = 'Project is On Hold. No phases can be marked as complete.';
			return Response::json([
				'status'      => $response['status'],
				'messages'    => $response['messages']
			]);
		}
	
		// $phases = ConstructionProjectPhase::select('id', 'completed_at')->where('construction_project_id', '=', $request->phase['construction_project_id'])->get();
		// foreach ($phases as $item) {
		// 	if ($item->id == $request->phase['id']) {
		// 		break;
		// 	}

		// 	if ($item->id != $request->phase && is_null($item->completed_at)) {
		// 		$response['messages'][] = 'Project has a previous phase that was not completed. This phase cannot be marked as complete.';
		// 		return Response::json([
		// 			'status'      => $response['status'],
		// 			'messages'    => $response['messages']
		// 		]);
		// 	}
		// }

		// If has_labor, must have at least one Labor vendor
		if ($phase->has_labor) {
			$labor = $phase->invoices()
				->whereHas('vendor', function ($q) {
					$q->hasTradesLike(['Labor']);
				})
				->get();
			if (!count($labor)) {
				$response['messages'][] = 'No Labor Vendors Recorded.';
			}
		}
		// If has_material, must have at least one Material vendor
		if ($phase->has_material) {
			$material = $phase->invoices()
				->whereHas('vendor', function ($q) {
					$q->hasTradesLike(['Material']);
				})
				->get();
			if (!count($material)) {
				$response['messages'][] = 'No Material Vendors Recorded.';
			}
		}
		// If has_quantity, all Material vendors must have recorded quantity
		if ($phase->has_quantity) {
			$quantity = $phase->invoices()
				->whereHas('vendor', function ($q) {
					$q->hasTradesLike(['Material']);
				})
				->whereNull('quantity')
				->get();
			if (count($quantity)) {
				foreach ($quantity as $vendor) {
					$response['messages'][] = 'Material Vendor '. $vendor->vendor->name .' is missing quantity.';
				}
			}
		}

		foreach ($phase->purchaseOrders as $po) {
			if ($po->order_number == null || $po->amount == null || $po->amount == 0) {
				$response['messages'][] = 'The office must add a valid purchase order to this phase in order to mark this phase complete.';
			}
		}

		if ($phase->has_quantity) {
			//check for any possible labor vendors
			//they should be skipped
			$laborVendors = $phase->invoices()
				->whereHas('vendor', function ($q) {
					$q->hasTradesLike(['Labor']);
				})
				->get();
			
			$totalLabors = count($laborVendors);
			
			$laborVendorsIDs = [];
			
			if (!empty($totalLabors)) {
				foreach ($laborVendors as $v) {
					array_push($laborVendorsIDs, $v->vendor_id);
				}
			}
			
			
			foreach ($phase->invoices as $invoice) {
				$vendor = $invoice->vendor_id;
				//do a comparison and if match then skip
				if (!empty($totalLabors)) {
					if (in_array($vendor, $laborVendorsIDs)) {
						continue;
					}
				}
				
				//resume
				if ($invoice->quantity == null || $invoice->quantity == 0) {
					$response['messages'][] = 'There is a invoice that requires having a quantity entered.';
					break;
				}
			}
		}

		if (!empty($response['messages'])) {
			return $response;
		}

		// If everything above passes, mark the phase as complete
		$response['status']  = 'success';
		$phase->completed_at = Carbon::now();
		$phase->completed_by = $request->user['id'];
		$phase->save();
		$phase->load(['purchaseOrders']);

		//Call notes attached to project
		$notes = ConstructionProjectNote::with('user')
			->where('construction_project_id', '=', $request->phase['construction_project_id'])
			->orderBy('created_at', 'DESC')
			->get()
			->toArray();

		//Add notes array to $phase Object
		$phase->notes = $notes;
			
		event(new ConstructionProjectPhaseCompleted($phase));
		// We need to check to see if all phases
		// are complete. If so, mark the project as completed
		if ($phase->constructionProject->isComplete()) {
			$phase->constructionProject->completed_at = Carbon::now();
			$phase->constructionProject->save();
		}

		return Response::json([
			'status'      => $response['status'],
			'messages'    => $response['messages'],
			'completer'   => $phase->completer->name,
			'completedAt' => $phase->completed_at->toDateString(),
			'phaseId'     => $phase->id
		]);
	}

	/**
	 * API endpoint to mark a phase as reopened (if it can)
	 *
	 * @param  Request $request
	 * @return array
	 */
	public function reopenPhase(Request $request)
	{
		$this->validate(request(), [
			'phase' => 'required',
		]);

		$response = ['messages' => [], 'success' => false];

		$phase = ConstructionProjectPhase::find($request->phase['id']);

		if ($phase->constructionProject->on_hold) {
			$response['messages'][] = 'Project is On Hold. No phases can be marked as reopened.';
		}

		if (!empty($response['messages'])) {
			return $response;
		}

		// If everything above passes, mark the phase as complete
		$response['success'] = true;
		$phase->completed_at = null;
		$phase->completed_by = null;
		$phase->save();

		return $response;
	}

	/**
	 * Fetches vendors based off the phases' LMQ status
	 *
	 * @param  Request $request
	 * @return Illuminate\Support\Facades\Response
	 */
	public function fetchVendors(Request $request)
	{
		$this->validate($request, [
			'phase' => 'required',
		]);

		// Start building the vendor query
		$vendors = Vendor::hasDivisionsLike(['Construction'])
			->with('trades')->orderBy('name', 'ASC');

		// Do we want to filter by trades?
		// $trades = $this->parseTrades(['Labor', 'Material', 'Concrete', 'Landscape', 'Framing', 'Pavers', 'Carpentry', 'Fencing']);


		// if ($trades->isNotEmpty()) {
		// 	$vendors->hasTradesLike($trades->toArray());
		// }

		return response()->json(['vendors' => $vendors->get()]);
	}

	/**
	 * Parse the given trades and only return items that were included in this
	 * request.
	 *
	 * @param  array  $trades
	 * @return \Illuminate\Support\Collection
	 */
	protected function parseTrades(array $trades)
	{
		return collect($trades)->filter(function ($trade) {
			return request()->has('has_'.strtolower($trade));
		});
	}


	/**
	 * Fetches all `cpm` from the system
	 *
	 * @return User
	 */
	public function fetchConstructionManagers()
	{
		$managers = User::whereHas('roles', function ($q) {
			$q->where('name', 'cpm')->orWhere('name', 'cdir');
		})->with(['contact', 'constructionCategories'])->get();
		return $managers;
	}

	/**
	 * Fetches invoices attached to a work order.
	 *
	 * @param  Request $request
	 * @return WorkOrderInvoice
	 */
	public function fetchAwoInvoices(Request $request)
	{
		$this->validate(request(), [
			'order' => 'required',
		]);
		$invoices = WorkOrderInvoice::with(['vendor' => function ($q) {
			$q->with('trades');
		}])
			->where('work_order_id', $request->order)
			->get();
		return $invoices;
	}

	/**
	 * Creates a work order invoice.
	 *
	 * @param  Request $request
	 * @return WorkOrderInvoice
	 */
	public function createAwoInvoice(Request $request)
	{
		$this->validate(request(), [
			'invoice.construction_project_work_order_id' => 'required',
			'invoice.vendor_id' => 'required',
		]);

		$invoice = WorkOrderInvoice::create([
			'work_order_id' => $request->invoice['construction_project_work_order_id'],
			'vendor_id' => $request->invoice['vendor_id']
		]);

		$invoice = WorkOrderInvoice::with(['vendor' => function ($q) {
			$q->with('trades');
		}])
			->where('id', '=', $invoice->id)
			->first();
		return $invoice;
	}

	/**
	 * Updates an work order invoice.
	 *
	 * @param  Request $request
	 * @return WorkOrderInvoice
	 */
	public function updateAwoInvoice(Request $request)
	{
		$this->validate(request(), [
			'invoice' => 'required',
		]);
		$invoice = WorkOrderInvoice::find($request->invoice['id']);
		$invoice->update([
			'amount' => str_replace(',', '', $request->invoice['amount']),
			'material' => $request->invoice['material'],
			'quantity' => $request->invoice['quantity'],
			'paid' => $request->invoice['paid'],
			'message' => $request->invoice['message'],
		]);
		return $invoice;
	}

	/**
	 * Deletes a work invoice
	 *
	 * @param  Request $request
	 * @return void
	 */
	public function deleteAwoInvoice(Request $request)
	{
		$this->validate(request(), [
			'invoice' => 'required'
		]);
		$invoice = WorkOrderInvoice::find($request->invoice['id']);
		$invoice->delete();
	}

	public function fetchSuperintendents(Request $request)
	{
		$project = ConstructionProject::find($request->id);

		return $project->homebuilder->superintendents;
	}
}
