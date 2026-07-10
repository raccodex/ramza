<?php
if ($wo['config']['website_mode'] != 'linkedin') {
	$error_code    = 5;
    $error_message = 'linkedin mode not enabled';
    $response_data       = array(
        'api_status'     => '404',
        'errors'         => array(
            'error_id'   => $error_code,
            'error_text' => $error_message
        )
    );
    echo json_encode($response_data, JSON_PRETTY_PRINT);
    exit();
}

$types = array('add_experience','delete_experience','edit_experience','get_user_experience','get_experience_by_id','add_certification','delete_certification','edit_certification','get_user_certification','get_certification_by_id','add_project','delete_project','edit_project','get_user_projects','get_project_by_id','open_to_find_job','delete_job','edit_job','providing_services','delete_service','edit_providing_services','get_open_to','get_open_to_by_id','search','get_open_posts');
if (!empty($_POST['type']) && in_array($_POST['type'], $types)) {

	if ($_POST['type'] == 'add_experience') {
		try {
			addExperienceValidation();


	        $experience_end = '';
	        $image = '';
	        $link = '';
	        $headline = '';

	        if (!empty($_POST['experience_end'])) {
	            $experience_end = Wo_Secure($_POST['experience_end']);
	        }
	        if (!empty($_POST['headline'])) {
	            $headline = Wo_Secure($_POST['headline']);
	        }
	        if (!empty($_POST['link'])) {
	            $link = Wo_Secure($_POST['link']);
	        }
	        if (!empty($_FILES["image"])) {
	            $fileInfo = array(
	                'file' => $_FILES["image"]["tmp_name"],
	                'name' => $_FILES['image']['name'],
	                'size' => $_FILES["image"]["size"],
	                'type' => $_FILES["image"]["type"],
	                'types' => 'jpg,png,jpeg,gif'
	            );
	            $file     = Wo_ShareFile($fileInfo, 1);
	            if (!empty($file) && !empty($file['filename'])) {
	                $image = $file['filename'];
	            }
	        }
			$insert_data = array('title' => Wo_Secure($_POST['title']),
	                             'company_name' => Wo_Secure($_POST['company_name']),
	                             'location' => Wo_Secure($_POST['location']),
	                             'experience_start' => Wo_Secure($_POST['experience_start']),
	                             'experience_end' => $experience_end,
	                             'industry' => Wo_Secure($_POST['industry']),
	                             'description' => Wo_Secure($_POST['description'],0,true,1),
	                             'image' => $image,
	                             'link' => $link,
	                             'headline' => $headline,
	                             'time' => time(),
	                             'user_id' => $wo['user']['id'],
	                             'employment_type' => Wo_Secure($_POST['employment_type']));
	        $id = $db->insert(T_USER_EXPERIENCE,$insert_data);
	        if (!empty($id)) {
	        	$experience = $db->where('id',$id)->getOne(T_USER_EXPERIENCE);
	        	$experience->image = Wo_GetMedia($experience->image);
	        	$response_data = array(
	                'api_status' => 200,
	                'message' => $wo['lang']['experience_successfully_created'],
	                'data' => $experience
	            );
	        }
	        else{
	        	throw new Exception($wo['lang']['something_wrong']);
	        } 
	    } catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }
    if ($_POST['type'] == 'delete_experience') {

    	try {
			deleteExperienceValidation();

			if (!empty($wo['experience']->image)) {
                @unlink($wo['experience']->image);
                Wo_DeleteFromToS3($wo['experience']->image);
            }
            $db->where('id',$wo['experience']->id)->delete(T_USER_EXPERIENCE);

            $response_data = array(
                'api_status' => 200,
                'message' => 'Experience deleted successfully'
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'edit_experience') {
    	try {
			editExperienceValidation();

			$update_data = [];

			if (!empty($_POST['title'])) {
		        $update_data['title'] = Wo_Secure($_POST['title']);
		    }

		    if (!empty($_POST['company_name'])) {
		        $update_data['company_name'] = Wo_Secure($_POST['company_name']);
		    }
		    if (!empty($_POST['employment_type']) && !in_array($_POST['employment_type'], array_keys($wo['employment_type']))) {
		        $update_data['employment_type'] = Wo_Secure($_POST['employment_type']);
		    }
		    if (!empty($_POST['location'])) {
		        $update_data['location'] = Wo_Secure($_POST['location']);
		    }
		    if (!empty($_POST['experience_start'])) {
		        $update_data['experience_start'] = Wo_Secure($_POST['experience_start']);
		    }
		    if (!empty($_POST['industry'])) {
		        $update_data['industry'] = Wo_Secure($_POST['industry']);
		    }
		    if (!empty($_POST['description'])) {
		        $update_data['description'] = Wo_Secure($_POST['description'],0,true,1);
		    }
		    if (!empty($_POST['link'])) {
		        $update_data['link'] = Wo_Secure($_POST['link']);
		    }
		    if (!empty($_POST['experience_end'])) {
		        $update_data['experience_end'] = Wo_Secure($_POST['experience_end']);
		    }

            if (!empty($_POST['headline'])) {
                $update_data['headline'] = Wo_Secure($_POST['headline']);
            }
            if (!empty($_FILES["image"])) {
                $fileInfo = array(
                    'file' => $_FILES["image"]["tmp_name"],
                    'name' => $_FILES['image']['name'],
                    'size' => $_FILES["image"]["size"],
                    'type' => $_FILES["image"]["type"],
                    'types' => 'jpg,png,jpeg,gif'
                );
                $file     = Wo_ShareFile($fileInfo, 1);
                if (!empty($file) && !empty($file['filename'])) {
                    $image = $file['filename'];
                    if (!empty($wo['experience']->image)) {
                        @unlink($wo['experience']->image);
                        Wo_DeleteFromToS3($wo['experience']->image);
                    }
                    $update_data['image'] = Wo_Secure($_POST['image']);
                }
            }

            $id = $db->where('id',$wo['experience']->id)->update(T_USER_EXPERIENCE,$update_data);
            $experience = $db->where('id',$wo['experience']->id)->getOne(T_USER_EXPERIENCE);
	        $experience->image = Wo_GetMedia($experience->image);

            $response_data = array(
            	'api_status' => 200,
                'message' => $wo['lang']['experience_successfully_updated'],
                'data' => $experience
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'get_user_experience') {
    	try {
			getUserExperienceValidation();

			$offset = (!empty($_POST['offset']) && is_numeric($_POST['offset']) && $_POST['offset'] > 0 ? Wo_Secure($_POST['offset']) : 0);
		    $limit = (!empty($_POST['limit']) && is_numeric($_POST['limit']) && $_POST['limit'] > 0 && $_POST['limit'] <= 50 ? Wo_Secure($_POST['limit']) : 20);

		    if (!empty($offset)) {
		    	$db->where('id',$offset,'<');
		    }

			$experiencesData = [];
			$experiences = $db->where('user_id',Wo_Secure($_POST['user_id']))->orderBy('id','DESC')->get(T_USER_EXPERIENCE,$limit);

			if (!empty($experiences)) {
				foreach ($experiences as $key => $value) {
					$value->image = Wo_GetMedia($value->image);
					$experiencesData[] = $value;
				}
			}

			$response_data = array(
            	'api_status' => 200,
                'data' => $experiencesData
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }
    if ($_POST['type'] == 'get_experience_by_id') {
    	try {
			getExperienceByIdValidation();

			$experience = $db->where('id',Wo_Secure($_POST['id']))->getOne(T_USER_EXPERIENCE);

			if (empty($experience)) {
				throw new Exception("experience not found");
			}

	        $experience->image = Wo_GetMedia($experience->image);

			$response_data = array(
            	'api_status' => 200,
                'data' => $experience
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'add_certification') {
    	try {
			addCertificationValidation();

			$pdf = '';
            $filename = '';
            $certification_end = null;
	        $credential_id = '';
	        $credential_url = '';
            if (!empty($_POST['certification_end'])) {
                $certification_end = Wo_Secure($_POST['certification_end']);
            }
            if (!empty($_POST['credential_id'])) {
                $credential_id = Wo_Secure($_POST['credential_id']);
            }
            if (!empty($_POST['credential_url'])) {
                $credential_url = Wo_Secure($_POST['credential_url']);
            }
            if (!empty($_FILES["pdf"])) {
                $fileInfo = array(
                    'file' => $_FILES["pdf"]["tmp_name"],
                    'name' => $_FILES['pdf']['name'],
                    'size' => $_FILES["pdf"]["size"],
                    'type' => $_FILES["pdf"]["type"],
                    'types' => 'pdf'
                );
                $amazone_s3 = $wo['config']['amazone_s3'];
                $wasabi_storage = $wo['config']['wasabi_storage'];
                $backblaze_storage = $wo['config']['backblaze_storage'];
                $ftp_upload = $wo['config']['ftp_upload'];
                $spaces = $wo['config']['spaces'];
                $cloud_upload = $wo['config']['cloud_upload'];
                $wo['config']['amazone_s3'] = 0;
                $wo['config']['wasabi_storage'] = 0;
                $wo['config']['backblaze_storage'] = 0;
                $wo['config']['ftp_upload'] = 0;
                $wo['config']['spaces'] = 0;
                $wo['config']['cloud_upload'] = 0;

                $file     = Wo_ShareFile($fileInfo, 1);

                $wo['config']['amazone_s3'] = $amazone_s3;
                $wo['config']['wasabi_storage'] = $wasabi_storage;
                $wo['config']['backblaze_storage'] = $backblaze_storage;
                $wo['config']['ftp_upload'] = $ftp_upload;
                $wo['config']['spaces'] = $spaces;
                $wo['config']['cloud_upload'] = $cloud_upload;
                if (!empty($file) && !empty($file['filename'])) {
                    $pdf = $file['filename'];
                    $filename = $file['name'];
                }
            }
            $insert_data = array('name' => Wo_Secure($_POST['name']),
                                 'issuing_organization' => Wo_Secure($_POST['issuing_organization']),
                                 'credential_id' => $credential_id,
                                 'credential_url' => $credential_url,
                                 'certification_start' => Wo_Secure($_POST['certification_start']),
                                 'certification_end' => $certification_end,
                                 'pdf' => $pdf,
                                 'filename' => $filename,
                                 'time' => time(),
                                 'user_id' => $wo['user']['id']);
            $id = $db->insert(T_USER_CERTIFICATION,$insert_data);
            if (!empty($id)) {
            	$certification = $db->where('id',$id)->getOne(T_USER_CERTIFICATION);
	        	$certification->pdf = Wo_GetMedia($certification->pdf);
            	$response_data = array(
	            	'api_status' => 200,
	                'message' => $wo['lang']['certification_successfully_created'],
	                'data' => $certification,
	            );
            }
            else{
            	throw new Exception($wo['lang']['something_wrong']);
            } 

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'delete_certification') {
    	try {
			deleteCertificationValidation();

			if (!empty($wo['certification']->pdf)) {
                @unlink($wo['certification']->pdf);
                Wo_DeleteFromToS3($wo['certification']->pdf);
            }
            $db->where('id',$wo['certification']->id)->delete(T_USER_CERTIFICATION);

            $response_data = array(
                'api_status' => 200,
                'message' => 'Certification deleted successfully'
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'edit_certification') {
    	try {
			editCertificationValidation();

			$update_data = [];

			if (!empty($_POST['name'])) {
		        $update_data['name'] = Wo_Secure($_POST['name']);
		    }

		    if (!empty($_POST['issuing_organization'])) {
		        $update_data['issuing_organization'] = Wo_Secure($_POST['issuing_organization']);
		    }

		    if (!empty($_POST['credential_id'])) {
		        $update_data['credential_id'] = Wo_Secure($_POST['credential_id']);
		    }

		    if (!empty($_POST['credential_url'])) {
		        $update_data['credential_url'] = Wo_Secure($_POST['credential_url']);
		    }

		    if (!empty($_POST['certification_start'])) {
		        $update_data['certification_start'] = Wo_Secure($_POST['certification_start']);
		    }

		    if (!empty($_POST['certification_end'])) {
		        $update_data['certification_end'] = Wo_Secure($_POST['certification_end']);
		    }

		    if (!empty($_FILES["pdf"])) {
                $fileInfo = array(
                    'file' => $_FILES["pdf"]["tmp_name"],
                    'name' => $_FILES['pdf']['name'],
                    'size' => $_FILES["pdf"]["size"],
                    'type' => $_FILES["pdf"]["type"],
                    'types' => 'pdf'
                );
                $amazone_s3 = $wo['config']['amazone_s3'];
                $wasabi_storage = $wo['config']['wasabi_storage'];
                $backblaze_storage = $wo['config']['backblaze_storage'];
                $ftp_upload = $wo['config']['ftp_upload'];
                $spaces = $wo['config']['spaces'];
                $cloud_upload = $wo['config']['cloud_upload'];
                $wo['config']['amazone_s3'] = 0;
                $wo['config']['wasabi_storage'] = 0;
                $wo['config']['backblaze_storage'] = 0;
                $wo['config']['ftp_upload'] = 0;
                $wo['config']['spaces'] = 0;
                $wo['config']['cloud_upload'] = 0;

                $file     = Wo_ShareFile($fileInfo, 1);

                $wo['config']['amazone_s3'] = $amazone_s3;
                $wo['config']['wasabi_storage'] = $wasabi_storage;
                $wo['config']['backblaze_storage'] = $backblaze_storage;
                $wo['config']['ftp_upload'] = $ftp_upload;
                $wo['config']['spaces'] = $spaces;
                $wo['config']['cloud_upload'] = $cloud_upload;
                if (!empty($file) && !empty($file['filename'])) {
                    $update_data['pdf'] = $file['filename'];
                    $update_data['filename'] = $file['name'];

                    if (!empty($wo['certification']->pdf)) {
                        @unlink($wo['certification']->pdf);
                        Wo_DeleteFromToS3($wo['certification']->pdf);
                    }
                }
            }




			$id = $db->where('id',$wo['certification']->id)->update(T_USER_CERTIFICATION,$update_data);
            $certification = $db->where('id',$wo['certification']->id)->getOne(T_USER_CERTIFICATION);
	        $certification->pdf = Wo_GetMedia($certification->pdf);

            $response_data = array(
            	'api_status' => 200,
                'message' => $wo['lang']['certification_successfully_updated'],
                'data' => $certification
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'get_user_certification') {
    	try {
			getUserCertificationValidation();

			$offset = (!empty($_POST['offset']) && is_numeric($_POST['offset']) && $_POST['offset'] > 0 ? Wo_Secure($_POST['offset']) : 0);
		    $limit = (!empty($_POST['limit']) && is_numeric($_POST['limit']) && $_POST['limit'] > 0 && $_POST['limit'] <= 50 ? Wo_Secure($_POST['limit']) : 20);

		    if (!empty($offset)) {
		    	$db->where('id',$offset,'<');
		    }

			$certificationsData = [];
			$certifications = $db->where('user_id',Wo_Secure($_POST['user_id']))->orderBy('id','DESC')->get(T_USER_CERTIFICATION,$limit);

			if (!empty($certifications)) {
				foreach ($certifications as $key => $value) {
					$value->pdf = Wo_GetMedia($value->pdf);
					$certificationsData[] = $value;
				}
			}

			$response_data = array(
            	'api_status' => 200,
                'data' => $certificationsData
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'get_certification_by_id') {
    	try {
			getCertificationByIdValidation();

			$certification = $db->where('id',Wo_Secure($_POST['id']))->getOne(T_USER_CERTIFICATION);

			if (empty($certification)) {
				throw new Exception("certification not found");
			}

	        $certification->image = Wo_GetMedia($certification->pdf);

			$response_data = array(
            	'api_status' => 200,
                'data' => $certification
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'add_project') {
    	try {
			addProjectValidation();

			$project_end = '';
	        $credential_id = '';
	        $project_url = '';
	        $description = '';
	        $associated_with = '';
			if (!empty($_POST['project_end'])) {
                $project_end = Wo_Secure($_POST['project_end']);
            }
            if (!empty($_POST['associated_with'])) {
                $associated_with = Wo_Secure($_POST['associated_with']);
            }
            if (!empty($_POST['description'])) {
                $description = Wo_Secure($_POST['description'],0,true,1);
            }
            if (!empty($_POST['project_url'])) {
                $project_url = Wo_Secure($_POST['project_url']);
            }
            $insert_data = array('name' => Wo_Secure($_POST['name']),
                                 'description' => $description,
                                 'associated_with' => $associated_with,
                                 'project_url' => $project_url,
                                 'project_start' => Wo_Secure($_POST['project_start']),
                                 'project_end' => $project_end,
                                 'time' => time(),
                                 'user_id' => $wo['user']['id']);
            $id = $db->insert(T_USER_PROJECTS,$insert_data);
            if (!empty($id)) {
            	$project = $db->where('id',$id)->getOne(T_USER_PROJECTS);
            	$response_data = array(
	            	'api_status' => 200,
	                'message' => $wo['lang']['project_successfully_added'],
	                'data' => $project,
	            );
            }
            else{
                throw new Exception($wo['lang']['something_wrong']);
            }

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'delete_project') {
    	try {
			deleteProjectValidation();

            $db->where('id',$wo['project']->id)->delete(T_USER_PROJECTS);

            $response_data = array(
                'api_status' => 200,
                'message' => 'Project deleted successfully'
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'edit_project') {
    	try {
			editProjectValidation();

			$update_data = [];

			if (!empty($_POST['name'])) {
		        $update_data['name'] = Wo_Secure($_POST['name']);
		    }

			if (!empty($_POST['description'])) {
		        $update_data['description'] = Wo_Secure($_POST['description']);
		    }
		    
			if (!empty($_POST['associated_with'])) {
		        $update_data['associated_with'] = Wo_Secure($_POST['associated_with']);
		    }
		    
			if (!empty($_POST['project_start'])) {
		        $update_data['project_start'] = Wo_Secure($_POST['project_start']);
		    }
		    
			if (!empty($_POST['project_end'])) {
		        $update_data['project_end'] = Wo_Secure($_POST['project_end']);
		    }

			$id = $db->where('id',$wo['project']->id)->update(T_USER_PROJECTS,$update_data);
            $project = $db->where('id',$wo['project']->id)->getOne(T_USER_PROJECTS);

            $response_data = array(
            	'api_status' => 200,
                'message' => $wo['lang']['project_successfully_updated'],
                'data' => $project
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'get_user_projects') {
    	try {
			getUserProjectsValidation();

			$offset = (!empty($_POST['offset']) && is_numeric($_POST['offset']) && $_POST['offset'] > 0 ? Wo_Secure($_POST['offset']) : 0);
		    $limit = (!empty($_POST['limit']) && is_numeric($_POST['limit']) && $_POST['limit'] > 0 && $_POST['limit'] <= 50 ? Wo_Secure($_POST['limit']) : 20);

		    if (!empty($offset)) {
		    	$db->where('id',$offset,'<');
		    }

			$projectsData = [];
			$projects = $db->where('user_id',Wo_Secure($_POST['user_id']))->orderBy('id','DESC')->get(T_USER_PROJECTS,$limit);

			if (!empty($projects)) {
				foreach ($projects as $key => $value) {
					$projectsData[] = $value;
				}
			}

			$response_data = array(
            	'api_status' => 200,
                'data' => $projectsData
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'get_project_by_id') {
    	try {
			getProjectByIdValidation();

			$project = $db->where('id',Wo_Secure($_POST['id']))->getOne(T_USER_PROJECTS);

			if (empty($project)) {
				throw new Exception("Project not found");
			}

			$response_data = array(
            	'api_status' => 200,
                'data' => $project
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'open_to_find_job') {
    	try {
			addOpenToJobValidation();

			$insert_data = array('user_id' => $wo['user']['id'],
                                 'job_title' => Wo_Secure($_POST['job_title']),
                                 'job_location' => Wo_Secure($_POST['job_location']),
                                 'workplaces' => Wo_Secure(implode(",",$_POST['workplaces'])),
                                 'job_type' => Wo_Secure(implode(",",$_POST['job_type'])),
                                 'type' => 'find_job',
                                 'time' => time());
            $id = $db->insert(T_USER_OPEN_TO,$insert_data);
            if (!empty($id)) {
            	$job = $db->where('id',$id)->getOne(T_USER_OPEN_TO);
		        $response_data = array('api_status' => 200,
		                        'message' => $wo['lang']['job_preferences_saved_successfully'],
		                        'data' => $job,
		                    );
		    }
		    else{
		    	throw new Exception($wo['lang']['something_wrong']);
		    }

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'delete_job') {
    	try {
			deleteOpenToJobValidation();

            $db->where('id',$wo['job']->id)->delete(T_USER_OPEN_TO);

            $response_data = array(
                'api_status' => 200,
                'message' => 'Job deleted successfully'
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'edit_job') {
    	try {
			editOpenToJobValidation();

			$update_data = [];

			if (!empty($_POST['job_title'])) {
	            $update_data['job_title'] = Wo_Secure($_POST['job_title']);
	        }

			if (!empty($_POST['job_location'])) {
	            $update_data['job_location'] = Wo_Secure($_POST['job_location']);
	        }

			if (!empty($_POST['workplaces'])) {
	            $update_data['workplaces'] = Wo_Secure(implode(",",$_POST['workplaces']));
	        }

			if (!empty($_POST['job_type'])) {
	            $update_data['job_type'] = Wo_Secure(implode(",",$_POST['job_type']));
	        }

            $db->where('id',$wo['job']->id)->update(T_USER_OPEN_TO,$update_data);

            $job = $db->where('id',$wo['job']->id)->getOne(T_USER_OPEN_TO);

            $response_data = array(
                'api_status' => 200,
                'message' => 'Job updated successfully',
                'data' => $job
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'providing_services') {
    	try {
			addOpenToProvidingServicesValidation();


			$_POST['services'] = preg_replace('/on[^<>=]+=[^<>]*/m', '', $_POST['services']);
		    $_POST['services'] = strip_tags($_POST['services']);
		    $insert_data = array('user_id' => $wo['user']['id'],
		                         'services' => Wo_Secure($_POST['services']),
	                             'job_location' => Wo_Secure($_POST['job_location']),
	                             'description' => Wo_Secure($_POST['description'],0,true,1),
	                             'time' => time(),
	                             'type' => 'service');
            $id = $db->insert(T_USER_OPEN_TO,$insert_data);
            if (!empty($id)) {
            	$services = $db->where('id',$id)->getOne(T_USER_OPEN_TO);
            	$response_data = array(
            		'api_status' => 200,
                    'message' => $wo['lang']['services_saved_successfully'],
                    'data' => $services,
                );
		    }
		    else{
		        throw new Exception($wo['lang']['something_wrong']);
		    }

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'delete_service') {
    	try {
			deleteOpenToProvidingServicesValidation();


			$db->where('id',$wo['services']->id)->delete(T_USER_OPEN_TO);

            $response_data = array(
                'api_status' => 200,
                'message' => 'Services deleted successfully'
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'edit_providing_services') {
    	try {
			editOpenToProvidingServicesValidation();

			$update_data = [];

			if (!empty($_POST['services'])) {
	            $update_data['services'] = Wo_Secure($_POST['services']);
	        }

			if (!empty($_POST['job_location'])) {
	            $update_data['job_location'] = Wo_Secure($_POST['job_location']);
	        }

			if (!empty($_POST['description'])) {
	            $update_data['description'] = Wo_Secure($_POST['description']);
	        }

            $db->where('id',$wo['services']->id)->update(T_USER_OPEN_TO,$update_data);

            $services = $db->where('id',$wo['services']->id)->getOne(T_USER_OPEN_TO);

            $response_data = array(
                'api_status' => 200,
                'message' => 'Services updated successfully',
                'data' => $services
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'get_open_to') {
    	try {
			getOpenToUserValidation();

			$offset = (!empty($_POST['offset']) && is_numeric($_POST['offset']) && $_POST['offset'] > 0 ? Wo_Secure($_POST['offset']) : 0);
		    $limit = (!empty($_POST['limit']) && is_numeric($_POST['limit']) && $_POST['limit'] > 0 && $_POST['limit'] <= 50 ? Wo_Secure($_POST['limit']) : 20);

		    if (!empty($offset)) {
		    	$db->where('id',$offset,'<');
		    }
		    if (!empty($_POST['open_type'])) {
		    	$db->where('type',Wo_Secure($_POST['open_type']));
		    }

			$projectsData = [];
			$projects = $db->where('user_id',Wo_Secure($_POST['user_id']))->orderBy('id','DESC')->get(T_USER_OPEN_TO,$limit);

			if (!empty($projects)) {
				foreach ($projects as $key => $value) {
					$projectsData[] = $value;
				}
			}

			$response_data = array(
            	'api_status' => 200,
                'data' => $projectsData
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'get_open_to_by_id') {
    	try {
			getOpenToUserByIdValidation();

			$project = $db->where('id',Wo_Secure($_POST['id']))->getOne(T_USER_OPEN_TO);

			if (empty($project)) {
				throw new Exception("Open To not found");
			}

			$response_data = array(
            	'api_status' => 200,
                'data' => $project
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'search') {
    	try {
		    $array = array(
		        'limit' => 10,
		        'search_type' => 'all'
		    );
		    $users = [];
		    $pages = [];
		    $groups = [];
		    $services = [];
		    if (!empty($_POST['keyword'])) {
		        $array['keyword'] = $_POST['keyword'];
		    }
		    if (!empty($_POST['certifications'])) {
		        $array['certifications'] = $_POST['certifications'];
		    }
		    if (!empty($_POST['search_type']) && in_array($_POST['search_type'], array('all','users','pages','groups','service'))) {
		        $array['search_type'] = $_POST['search_type'];
		    }
		    if (!empty($_POST['offset']) && is_numeric($_POST['offset'])) {
		        $array['offset'] = $_POST['offset'];
		    }
		    if (!empty($_POST['experience']) && is_numeric($_POST['experience'])) {
		        $array['experience'] = $_POST['experience'];
		    }
		    if (!empty($_POST['job_type'])) {
		        $array['job_type'] = $_POST['job_type'];
		    }
		    if (!empty($_POST['workplaces'])) {
		        $array['workplaces'] = $_POST['workplaces'];
		    }
		    $info = LinkedinSearch($array);
		    array_multisort( array_column($info, "sort_time"), SORT_DESC, $info );
		    if (count($info) > 20) {
		        $info = array_slice($info, 0, 20, true);
		    }
		    foreach ($info as $key => $wo['result']) {
		        if ($wo['result']['sort_type'] == 'user') {
		        	unset($wo['result']['password']);
		        	unset($wo['result']['email_code']);
		            $users[] = $wo['result'];
		        }
		        if ($wo['result']['sort_type'] == 'page') {
		            $pages[] = $wo['result'];
		        }
		        if ($wo['result']['sort_type'] == 'group') {
		            $groups[] = $wo['result'];
		        }
		        if ($wo['result']['sort_type'] == 'service') {
		            $services[] = $wo['result'];
		        }
		    }

			$response_data = array(
            	'api_status' => 200,
                'users' => $users,
                'pages' => $pages,
                'groups' => $groups,
                'services' => $services,
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }

    if ($_POST['type'] == 'get_open_posts') {
    	try {

    		$offset = (!empty($_POST['offset']) && is_numeric($_POST['offset']) && $_POST['offset'] > 0 ? Wo_Secure($_POST['offset']) : 0);
		    $limit = (!empty($_POST['limit']) && is_numeric($_POST['limit']) && $_POST['limit'] > 0 && $_POST['limit'] <= 50 ? Wo_Secure($_POST['limit']) : 20);

		    $posts = Wo_GetOpenToWorkPosts($limit,$offset);

			$response_data = array(
            	'api_status' => 200,
                'data' => $posts,
            );

		} catch (Exception $e) {
			$error_code    = 5;
		    $error_message = $e->getMessage();
		}
    }


}
else{
	$error_code    = 4;
    $error_message = 'type can not be empty';
}