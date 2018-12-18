@extends ('template.default-template')
@section ('content')
	<section class="bc-construction-section__detail-view">
		<div class="container-fluid">
			
			<div class="row">
				<div class="col-12">

					@include ('template.partials.success') @include('template.partials.errors') @if( $project->on_hold )

					<div class="alert alert-danger" role="alert">
						<strong>Project On Hold!</strong> {{ $project->on_hold_summary }}
					</div>
					@endif
					<h1>Lot #: {{ $project->lot_number }}</h1>
				</div>
			</div>
			
			<div class="row">
				
				<div class="col-12 col-lg-4 col-md-12 bc-custom-break">
					
					<div class="bc-accordion">
						@component('components.accordion-item', [ 
							'title' => 'Project Details', 
							'active' => 1, 
							'icon' => 'fa fa-ellipsis-v',
							'device_active' => [
								'tablet' => 1,
								'mobile' => 0
							]
						])
						
							@if ($project->archived)
							<div class="alert alert-warning" role="alert">
								This project has been marked as archived.
							</div>
							@endif

							<div class="card sub-card bc-card-component">
								<div class="card-header bc-card-component__header">
									<h5>Outlook</h5>
								</div>
								<div class="card-block pt-3">
									<div>
										<span>
											<strong>Project Submitted:</strong> {{ $project->created_at->format('m/d/Y') }}
										</span><br/>
										<span>
											<strong>Project Start Date:</strong> {!! $project->start_date ? $project->start_date->format('m/d/Y') : '<span class="text-danger">Not Scheduled</span>' !!}
										</span><br/>
										<span>
											@if ($project->isComplete())

												<strong>Project Complete Date:</strong> {!! $project->completed_at ? $project->completed_at->format('m/d/Y') : '<span class="text-danger">Not Complete</span>' !!}

											@endif
										</span>
										<span>
											<strong>Project Completion Status:</strong>
										</span>

									</div>

									<div class="progress">
										@php $completion = $project->getCompletionPercentage(); @endphp
										<div class="progress-bar bg-info progress-bar-animated" role="progressbar" style="width:{{ $completion }};">{{ $completion }}</div>
									</div>
									@if ($project->overview)
									<p class=" mtm"><strong>Overview:</strong> {{ $project->overview }}</p>
									@endif
									<hr class="mb-1">

									<constructionprojectnotes :project="{{ $project }}" :user="{{ Auth::user()->load(['roles']) }}"></constructionprojectnotes>

								</div>
							</div>

							<div class="card sub-card bc-card-component">
								<div class="card-header bc-card-component__header">
									<h5>Project Location</h5>
								</div>
								<div class="card-block pt-3">
									<div>
										<span class="row">
											<strong>Subdivision:</strong>&nbsp;{{ $project->subdivision->name }}
										</span>
										<span class="row">
											<strong>Lot #:</strong>&nbsp;{{ $project->lot_number }}
										</span>
										@if( $project->model )
										<span class="row">
											<strong>Model:</strong>&nbsp;{{ $project->model }}
										</span>
										@endif
										<span class="row">
											<strong>Address: </strong>&nbsp;
											<span >
												{!! $project->contact->fullAddress() !!}
											</span>
										</span>
									</div>
									<iframe class=" hidden-mobile" width="100%" height="300" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" src="https://maps.google.it/maps?q=<?php echo $project->contact->googleMapURL(); ?>&output=embed">
									</iframe>
								</div>
							</div>

							<div class="card sub-card bc-card-component">
								<div class="card-header bc-card-component__header">
									<h5>Homebuilder</h5>
								</div>
								<div class="card-block">
									<h5 class="mb-1">{{ $project->homebuilder->name }}</h5>
									<div>
										<a href="tel:+1{{ $project->homebuilder->contact->office_phone }}" class="hidden-desktop hidden-tablet text-left">
											<i class="fa fa-phone-square"></i> {{ $project->homebuilder->contact->office_phone }}
										</a>
									</div>
									@if ($project->homebuilder->contact->email)
									<div>
										<a href="mailto:{{ $project->homebuilder->contact->email }}" class="hidden-desktop hidden-tablet btn btn-md btn-block btn-cb-green text-left">
											<i class="fa fa-envelope-square"></i> {{ $project->homebuilder->contact->email }}
										</a>
									</div>
									@endif
								</div>
							</div>

							<div class="card sub-card bc-card-component">
								<div class="card-header bc-card-component__header">
									<h5>Superintendent</h5>
								</div>
								<div class="card-block">
									<h5 class="mb-1">{{ $project->superintendent->name }}</h5>
									<div>
										<a href="tel:+1{{ $project->superintendent->contact->mobile_phone }}" class="hidden-desktop hidden-tablet text-left">
											<i class="fa fa-phone-square"></i> {{ $project->superintendent->contact->mobile_phone }}
										</a>
										<br/> @if ($project->superintendent->contact->email)

										<a href="mailto:{{ $project->superintendent->contact->email }}" class="hidden-desktop hidden-tablet text-left">
											<i class="fa fa-envelope-square"></i> {{ $project->superintendent->contact->email }}
										</a>

										@endif
									</div>
								</div>
							</div>

							@role(['admin', 'op', 'cdir'])

								<div class="card sub-card bc-card-component">
									<div class="card-header bc-card-component__header">
										<h5>Actions</h5>
									</div>
									<div class="card-block p-1">
										<div class="btn-group mt-2 bc-group__actions">

											<a class="btn btn-sm btn-cb-blue bc-button__edit-details" href="{{ \ViewsHelper::host() }}/constructionprojects/{{ $project->id }}/edit"><i class="fa fa-pencil-square" aria-hidden="true"></i> Edit Details</a>

											<a class="btn btn-sm btn-cb-mid-gray bc-button__edit-phases" href="{{ \ViewsHelper::host() }}/constructionprojects/{{ $project->id }}/edit/phases"><i class="fa fa-file-text" aria-hidden="true"></i> Edit Phases</a>

											<a class="btn btn-sm btn-cb-gray bc-button__archive" href="{{ \ViewsHelper::host() }}/constructionprojects/{{ $project->id }}/archive"><i class="fa fa-archive" aria-hidden="true"></i> Archive</a>

										</div>
									</div>
								</div>

							@endrole
						
						@endcomponent
						
					</div>

					<div class="bc-accordion">
						@component('components.accordion-item', [ 
							'title' => 'Project Managers', 
							'active' => 0, 
							'icon' => 'fa fa-users font-lg',
							'device_active' => [
								'tablet' => 0,
								'mobile' => 0
							]
						]) 
						
							@foreach($project->projectManagers() as $manager )

								<div class="bc-section__title-divider">{{ $manager->name }}</div>
								<p class="mb-1">
									<a href="tel:{{ $manager->contact->mobile_phone }}" class="hidden-desktop hidden-tablet  text-left"><i class="fa fa-phone-square"></i> {{ $manager->contact->mobile_phone }}</a>
									<br/>
									<a href="mailto:{{ $manager->email }}" class="hidden-desktop hidden-tablet text-left"><i class="fa fa-envelope-square"></i> {{ $manager->email }}</a>
								</p>

							@endforeach 
						
						@endcomponent
					</div>

					<div class="bc-accordion">
						@component('components.accordion-item', [ 
							'title' => 'Material Takeoff', 
							'active' => 0, 
							'icon' => 'fa fa-picture-o font-lg font-lg',
							'device_active' => [
								'tablet' => 0,
								'mobile' => 0
							]
						])
							<constructionassets type="material-takeoff" :project="{{ $project }}"></constructionassets>
						@endcomponent
					</div>

					<div class="bc-accordion">
						@component('components.accordion-item', [ 
							'title' => 'Project Assets', 
							'active' => 0, 
							'icon' => 'fa fa-picture-o font-lg font-lg',
							'device_active' => [
								'tablet' => 0,
								'mobile' => 0
							]
						])
							<constructionassets type="project-assets" :project="{{ $project }}"></constructionassets>
						@endcomponent
					</div>

				</div>
				
				
				
				<!--
					------------------------------------
					MAIN SECTION
					------------------------------------
				-->
				
				<div class="col-12 col-lg-8 col-md-12 bc-custom-break">

					@foreach( $project->phases as $phase )

						<div class="bc-accordion bc-construction-phase">
							<?php $title = 'Phase - '.$phase->getDisplayRank() . ' : ' . $phase->name; ?>
							@component('components.accordion-item', [ 
								'title' => $title, 
								'active' => ( Entrust::hasRole(['admin','op']) || ($phase->user_id == Auth::user()->id) ), 
								'class' => 'mbxl', 
								'icon' => 'fa fa-ellipsis-h',
								'device_active' => [
									'tablet' => 0,
									'mobile' => 0
								]
							])
								
								<constructionphasecompletion :phase="{{ $phase }}" :user="{{ Auth::user()->load(['roles']) }}"></constructionphasecompletion>
								<p class="mb-0"><strong>Lot #:</strong> {{ $project->lot_number }}</p>
								<p class="mb-0"><strong>Manager:</strong> {{ $phase->user->name }}</p>

								@if( $phase->purchaseOrders->count() > 0) 
									@foreach( $phase->purchaseOrders as $po ) 
										@if(Entrust::hasRole(['admin', 'op','cdir']))
											<p class="mb-0">
												@if ($po->order_number && $po->amount)
													<strong>PO:</strong> {{ $po->order_number }} | 
													@if (isset($po->quantity) && $po->quantity != 0) 
														<strong>QTY:</strong> {{$po->quantity}} 
													@endif
													@if($po->amount) 
														| <strong>AMT:</strong> ${{$po->amount}} 
													@endif
													<span class="clear"></span>
													@if($po->sales_order_number)
														<strong>SO:</strong> {{ $po->sales_order_number }}
													@endif
												@else
													<strong class="text-danger">NEEDS PURCHASE ORDER</strong> 
												@endif
											</p>
										@else
											<p class="mb-0"><strong>PO:</strong> {{ $po->order_number }}</p>
										@endif 
									@endforeach 
								@endif

								<p class="mb-0"><strong>Category:</strong> {{ $phase->category }}</p>
								
								<phasecompleted initial-completer="{{ $phase->completed_by ? $phase->completer->name : null }}" initial-completed="{{ $phase->completed_at }}" :id="{{ $phase->id }}"></phasecompleted>
								
								<constructionvendors :phase="{{ $phase }}" :user="{{ Auth::user()->load('roles') }}"></constructionvendors>
							
							@endcomponent
							
						</div>
					@endforeach

					@foreach( $project->workOrders as $workOrder )
						@include('workorders.details', compact($workOrder))
					@endforeach

					<div class="bc-accordion">
						@component('components.accordion-item', [ 
							'title' => 'Create Work Order', 
							'active' => !empty($errors->all()), 
							'class' => 'mbxl', 
							'icon' => 'fa fa-file-text-o font-lg',
							'device_active' => [
								'tablet' => 0,
								'mobile' => 0
							]
						])
							@include('workorders.form')
						@endcomponent
					</div>

				</div>

			</div>

		</div>
		

	</section>
@endsection
