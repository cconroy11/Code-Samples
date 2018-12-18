<template>
    <div>
		<p class="mb-2"><b>Project Notes:</b></p>
        <div class="btn-group -flex-break">
            <button type="button" class="btn btn-xs btn-cb-dark-blue" @click="$modal.show(modalViewName)"><i aria-hidden="true" class="fa fa-sticky-note"></i> View ({{notes.length}})</button>
            <button type="button" class="btn btn-xs btn-cb-light-blue" @click="$modal.show(modalCreateName)"><i aria-hidden="true" class="fa fa-plus"></i> <span class="cb-dark-blue">Add</span></button>
        </div>

        <modal
            :name="modalViewName"
            :pivot-y="0.3"
            :adaptive="true"
            :scrollable="true"
            height="auto"
            classes="bc-modal"
			@opened="onModalOpen"
            @closed="onModalClosed"
            >
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Notes</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close" @click="$modal.hide(modalViewName)">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div v-for="(note, index) in notes" class="card-block" v-bind:key="index">
                        <h5 class="card-title">{{ note.user.name }}</h5>
                        <h6 class="card-subtitle mb-2 text-muted font-italic">{{ dateFormat(note.created_at, 'LLLL') }}</h6>

                        <div v-if="!note.editing">

                            <blockquote class="blockquote">
                                <p class="mb-0">{{ note.content }}</p>
                            </blockquote>

                            <div class="btn-group pull-right" v-if="canEdit(note)">
                                <button v-on:click="toggleEdit(note)" class="btn btn-xs btn-cb-blue mr-3">Edit</button>
                                <button v-on:click="deleteNote(note, index)" class="btn btn-xs btn-cb-gray">Delete</button>
                            </div>
                        </div>

                        <div v-else>
                            <div class="form-group">
                                <textarea class="form-control" v-model="note.content"></textarea>
                            </div>
                            <button class="btn btn-xs btn-success pull-right" v-on:click="toggleEdit(note)">Done</button>
                        </div>
                    </div>
                </div>
            </div>
        </modal>

        <modal
            :name="modalCreateName"
            :pivot-y="0.3"
            :adaptive="true"
            :scrollable="true"
            height="auto"
            classes="bc-modal"
			@opened="onModalOpen"
            @closed="onModalClosed">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Note</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close" @click="$modal.hide(modalCreateName)">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <textarea class="form-control" rows="6" v-model.trim="newNote.content"></textarea>
                    </div>
                    <div class="btn-group pull-right">
                        <button class="btn btn-xs btn-success mr-3" v-on:click="addNote()">Add</button>
                        <button class="btn btn-xs btn-cb-gray" v-on:click="cancelAddNote()">Cancel</button>
                    </div>
                </div>
            </div>
        </modal>
    </div>

</template>

<script>
module.exports = {
	http: {
		headers: {
			'X-CSRF-TOKEN': window.Laravel.csrfToken,
		},
	},
	props: ['project', 'user'],
	data: function() {
		return {
			modalViewName: 'viewNotes',
			modalCreateName: 'createNote',
			editing: false,
			newNote: {
				construction_project_id: this.project.id,
				user_id: this.user.id,
				content: null,
			},
			notes: [],
		};
	},
	created: function() {
		this.fetchNotes();
	},
	mounted: function() {},
	methods: {
		//modal
		onModalOpen: function() {
			MODALHELPER.whenOpen();
		},
		onModalClosed: function() {
			MODALHELPER.whenClosed();
		},
		fetchNotes: function() {
			this.$http
				.get('/api/constructionprojects/notes', {
					params: {
						project: this.project.id,
					},
				})
				.then(response => {
					// add a reactive `editing` property to each note
					response.data.map(function(obj) {
						return (obj.editing = false);
					});
					this.notes = response.data;
				});
		},
		canEdit: function(note) {
			if (note.user.id == this.user.id) {
				return true;
			} else if (this.hasRole(['admin'])) {
				return true;
			} else {
				return false;
			}
		},
		onViewClose: function() {
			$.each(this.notes, function(key, value) {
				value.editing = false;
			});
		},
		toggleEdit: function(note) {
			if (note.editing) {
				if (note.content) {
					this.$http
						.patch('/api/constructionprojects/notes', {
							note: note,
						})
						.then(
							response => {
								toastada.success('Note Edited!');
								note.editing = !note.editing;
							},
							response => {
								var message = response.statusText === '' ? 'Please try again.' : response.statusText;
								toastada.error('Error editing Note!<br><br><b>status</b>: ' + response.status + '<br><br> ' + message);
							}
						);
				} else {
					toastada.error('Note must have content!');
				}
			} else {
				note.editing = !note.editing;
			}
		},
		deleteNote: function(note, index) {
			var c = confirm('Are You Sure?');
			if (c) {
				this.$http
					.delete('/api/constructionprojects/notes', {
						params: {
							note: note,
						},
					})
					.then(
						response => {
							this.notes.splice(index, 1);
							toastada.success('Note Removed!');
						},
						response => {
							var message = response.statusText === '' ? 'Please try again.' : response.statusText;
							toastada.error('Error removing Note!<br><br><b>status</b>: ' + response.status + '<br><br> ' + message);
						}
					);
			} else {
				toastada.warning('Cancelled Delete!');
			}
		},
		addNote: function() {
			if (this.newNote.content) {
				this.$http
					.post('/api/constructionprojects/notes', {
						note: this.newNote,
					})
					.then(
						response => {
							response.data.editing = false; //adding a reactive editing property to the note
							this.notes.unshift(response.data);
							this.resetNewNote();
							this.$modal.hide(this.modalCreateName);
							toastada.success('Note Created!');
						},
						response => {
							var message = response.statusText === '' ? 'Please try again.' : response.statusText;
							toastada.error('Error adding Note!<br><br><b>status</b>: ' + response.status + '<br><br> ' + message);
							this.resetNewNote();
						}
					);
			} else {
				toastada.error('Note must have content!');
			}
		},
		cancelAddNote: function() {
			toastada.warning('Cancelled');
			this.$modal.hide(this.modalCreateName);
			this.resetNewNote();
		},
		resetNewNote: function() {
			this.newNote = {
				construction_project_id: this.project.id,
				user_id: this.user.id,
				content: null,
			};
		},
		dateFormat: function(date, format) {
			if (date) {
				var date = moment(date).format(format);
				return date;
			} else {
				return false;
			}
		},
	},
};
</script>
