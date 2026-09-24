// +------------------------------------------------------------------------+
// | @author Deen Doughouz (DoughouzForest)
// | @author_url 1: http://www.raccodex.com
// | @author_url 2: http://codecanyon.net/user/doughouzforest
// | @author_email: support@raccodex.com   
// +------------------------------------------------------------------------+
// | Ramza - The Ultimate Social Networking Platform
// | Copyright (c) 2017 Ramza. All rights reserved.
// +------------------------------------------------------------------------+

jQuery(document).ready(function($) {
	$(document).on('click', '.delete-reply', function(event) {
		event.preventDefault();
		$("#delete-reply").attr('data-reply-ident', $(this).attr("id")).modal("show");
	});	
	
	$(document).on('click', '.delete-thread', function(event) {
		event.preventDefault();
		$("#delete-thread").attr('data-thread-ident', $(this).attr("id")).modal("show");
	});
});