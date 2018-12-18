<?php

namespace App\Models\Construction;

use App\Models\Contact;
use App\Models\Homebuilder;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Subdivision;
use App\Models\Superintendent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Storage;
use Entrust;

class ConstructionProject extends Model
{
	protected $fillable = [
		'homebuilder_id', 'subdivision_id', 'superintendent_id', 'contact_id', 'lot_number', 'model', 'overview', 'material_takeoff_json', 'project_assets_json', 'start_date', 'on_hold', 'on_hold_summary', 'archived', 'updated_at', 'completed_at'
	];

	protected $dates = [
		'start_date',
		'completed_at'
	];

	public function contact()
	{
		return $this->belongsTo(Contact::class);
	}

	public function homebuilder()
	{
		return $this->belongsTo(Homebuilder::class)->withTrashed();
	}

	public function subdivision()
	{
		return $this->belongsTo(Subdivision::class)->withTrashed();
	}

	public function superintendent()
	{
		return $this->belongsTo(Superintendent::class)->withTrashed();
	}

	public function phases()
	{
		return $this->hasMany(ConstructionProjectPhase::class)->orderBy('rank');
	}

	public function vendors()
	{
		return $this->belongsToMany(Vendor::class);
	}

	public function getMaterialTakeoff()
	{
		return $this->material_takeoff_json ? json_decode($this->material_takeoff_json) : [];
	}

	public function workOrders()
	{
		return $this->hasMany(WorkOrder::class);
	}

	public function notes()
	{
		return $this->hasMany(ConstructionProjectNote::class)->orderBy('created_at', 'desc');
	}

	public function phaseInvoices()
	{
		return $this->hasMany(ConstructionProjectVendor::class);
	}

	public function areAllPhasesComplete()
	{
		foreach ($this->phases as $phase) {
			// phases needs to be complete
			if ($phase->completed_at === null) {
				return false;
			}
		}
		return true;
	}

	public function areAllPhaseVendorsPaid()
	{
		foreach ($this->phaseInvoices as $invoice) {
			// vendors needs to be paid
			if (!$invoice->paid) {
				return false;
			}
		}
		return true;
	}

	public function areAllWorkOrderVendorsPaid()
	{
		//Assume all work order invoices paid
		$all_paid = true;

		//Iterate through all work orders
		foreach ($this->workOrders as $workOrder) {
			//Iterate through all vendors for this work order
			foreach ($workOrder->workOrderVendors as $invoice) {
				if (!$invoice->paid) {
					$all_paid = false;
				}
			}
		}

		return $all_paid;
	}

	public function getNameAttribute()
	{
		return $this->homebuilder->name.' '.$this->subdivision->name.' '.$this->lot_number;
	}

	public function projectManagers()
	{
		//Construction Project Managers
		$cpm_managers = User::withRole('cpm')
			->whereHas('constructionProjectPhases', function ($q) {
				$q->where('construction_project_id', $this->id);
			})
			->orWhereHas('constructionWorkOrders', function ($q) {
				$q->where('construction_project_id', $this->id);
			})
			->withTrashed()
			->get();
		//Construction Directors
		$cdir_managers = User::withRole('cdir')
			->whereHas('constructionProjectPhases', function ($q) {
				$q->where('construction_project_id', $this->id);
			})
			->orWhereHas('constructionWorkOrders', function ($q) {
				$q->where('construction_project_id', $this->id);
			})
			->withTrashed()
			->get();

		return $cpm_managers->merge($cdir_managers);
	}

	public function addMaterialTakeoff($path, $caption)
	{
		$material_takeoff = json_decode($this->material_takeoff_json);
		$material_takeoff[] = ['url' => $path, 'caption' => $caption];
		$this->material_takeoff_json = json_encode($material_takeoff);
	}

	public function removeMaterialTakeoff($path)
	{
		$material_takeoff = $this->getMaterialTakeoff();
		foreach ($material_takeoff as $index => $asset) {
			if ($asset->url === $path) {
				unset($material_takeoff[$index]);
				break;
			}
		}
		$this->material_takeoff_json = json_encode(array_values($material_takeoff));
	}

	public function getProjectAssets()
	{
		return $this->project_assets_json ? json_decode($this->project_assets_json) : [];
	}

	public function addProjectAsset($path, $caption)
	{
		$project_assets = json_decode($this->project_assets_json);
		$project_assets[] = ['url' => $path, 'caption' => $caption];
		$this->project_assets_json = json_encode($project_assets);
	}

	public function removeProjectAsset($path)
	{
		$project_assets = $this->getProjectAssets();
		foreach ($project_assets as $index => $asset) {
			if ($asset->url === $path) {
				unset($project_assets[$index]);
				break;
			}
		}
		$this->project_assets_json = json_encode(array_values($project_assets));
	}

	public function getPhase()
	{
		$currentPhase = $this->phases()->whereNull('completed_at')->first();
		return $currentPhase ? $currentPhase->name : 'Project Completed';
	}


	public function hasPhase($name)
	{
		foreach ($this->phases as $phase) {
			if ($phase->name == $name) {
				return true;
			}
		}
		return false;
	}

	public function getCompletionPercentage()
	{
		$all = $this->phases()->count();
		$completed = $this->phases()->whereNotNull('completed_at')->count();

		if ($all != 0) {
			$num = round(($completed / $all) * 100);
		} else {
			$num = 0;
		}
		return $num . '%';
	}

	public function isComplete()
	{
		foreach ($this->phases as $phase) {
			// phases needs to be complete
			if (!$phase->completed) {
				return false;
			}
		}
		return true;
	}
}
