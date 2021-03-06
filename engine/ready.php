<?php 

require dirname(__FILE__)."/include/common.inc.php";
require dirname(__FILE__)."/library/ready.func.php";
require dirname(__FILE__)."/library/general.func.php";
require dirname(__FILE__)."/library/preprocess.func.php";
require dirname(__FILE__)."/library/organ.func.php";
require dirname(__FILE__)."/library/config.func.php";
require dirname(__FILE__)."/library/instruction.func.php";
require dirname(__FILE__)."/library/mem.func.php";
require dirname(__FILE__)."/library/ready.stack.balance.func.php";
require dirname(__FILE__)."/library/stero.graphic.class.php";
require_once dirname(__FILE__)."/../nasm.inc.php";
Instruction::init();

//////////////////////////////////////////
//捕获超时
$complete_finished = false; //执行完成标志
register_shutdown_function('shutdown_except');

//////////////////////////////////////////
//同时支持$_GET/$_POST/命令行输入 的参数
if (!isset($argv)){
	$argv = array();
}
CfgParser::get_params($argv);

if (!GeneralFunc::LogHasErr()){
	if (CfgParser::params('echo')){
		require dirname(__FILE__)."/library/debug_show.func.php";
	}	

	set_time_limit(CfgParser::params('timelimit'));	

	$file_format_parser = dirname(__FILE__)."/IOFormatParser/".CfgParser::params('type').".IO.php";
	
	if (file_exists($file_format_parser)){
		require $file_format_parser;
	}else{
		GeneralFunc::LogInsert('type without file format parser');
	}
	$bin_file = CfgParser::params('_file').".bin";
	$asm_file = CfgParser::params('_file').".asm";
	$rdy_file = CfgParser::params('_file').".rdy";     //obj 分析完成保存文件
	$out_file = CfgParser::params('_file').".out.asm";	

	define ('UNIQUEHEAD','UNEST_'); //独特的头部标志，防止与代码中字符冲突 (如冲突，增加随机字符，注:禁止增加下划线 英文字符必须为大写)
	$pattern_reloc = '/('.UNIQUEHEAD.'RELINFO_[\d]{1,}_[\d]{1,}_[\d]{1,})/';  //匹配 reloc 信息	
}
if (defined('DEBUG_ECHO')){
		CfgParser::params_show();
}
if (!GeneralFunc::LogHasErr()){
	////////////////////////////////////////////////////////

	$exetime_record = array();
	GeneralFunc::exetime_record(); //获取程序开始执行的时间

	////////////////////////////////////////////////////////
	//目标处理文件格式处理
		$myTables = array();
		@$handle = fopen(CfgParser::params('filename'),'rb');
		if (!$handle){
			GeneralFunc::LogInsert('fail to open file:'.CfgParser::params('filename'));
		}else{
			$buf = fread($handle,filesize(CfgParser::params('filename')));
			fclose($handle);

			$input_filesize = filesize(CfgParser::params('filename'));
			
			IOFormatParser::in_file_format();
			$exetime_record['analysis_input_file_format'] = GeneralFunc::exetime_record(); //获取程序执行的时间
		}
	///////////////////////////////////////////////////////
}

//读取配置文件
if (!GeneralFunc::LogHasErr()){

	$protect_section   = array();   // 保护段设置
	$dynamic_insert    = array();   // 动态赋值设置
    
	$preprocess_sec_name = CfgParser::GetPreprocess_sec();
	if (!count($preprocess_sec_name)){
	    GeneralFunc::LogInsert($language['without_act_sec']);        
	}else{
		//过滤 非指定 节表
		$ignore_sec = $preprocess_sec_name;
		$tmp = $myTables['CodeSectionArray'];
		foreach ($tmp as $a => $b){
			if (!isset($preprocess_sec_name[$b['name']])){
				unset ($myTables['CodeSectionArray'][$a]);
			}else{
			    unset($ignore_sec[$b['name']]);
			}
		}

		if (count($ignore_sec)){
			foreach ($ignore_sec as $a => $b){
				GeneralFunc::LogInsert($language['ignore_ready_sec'].$a,3);
			}
		}
        
		if (defined('DEBUG_ECHO')){
			DebugShowFunc::my_shower_07($myTables['CodeSectionArray'],$ignore_sec);
		}
	}




	if (CfgParser::get_preprocess_config('protect_section')){  // 保护段设置
		$protect_section = CfgParser::get_preprocess_config('protect_section');
		//检测是否重叠保护段
		if (PreprocessFunc::is_overlap_section($protect_section)){
			GeneralFunc::LogInsert($language['overlay_protect_section']);      
		}	
	}
	if (CfgParser::get_preprocess_config('dynamic_insert')){  // 动态写入设置
		$dynamic_insert = CfgParser::get_preprocess_config('dynamic_insert');
		//检测是否重叠保护段
		if (PreprocessFunc::is_overlap_section($dynamic_insert)){
			GeneralFunc::LogInsert($language['overlay_dynamic_insert']);      
		}	
	}	
}


if (!GeneralFunc::LogHasErr()){
	////关联保护段和混淆目标段
	$protect_section_array = false; //['sec_number']['rva'] => size ; (rva = 相对段开头的偏移地址)
	if (!empty($protect_section)){
		$protect_section_array = PreprocessFunc::bind_protect_section_2_sec($protect_section,$myTables['CodeSectionArray'],$language);
	}
	//var_dump ($protect_section_array);
}

if (!GeneralFunc::LogHasErr()){
	////关联保护段和混淆目标段
	$dynamic_insert_array = false; //['sec_number']['rva'] => size ; (rva = 相对段开头的偏移地址)
	if (!empty($dynamic_insert)){
		$dynamic_insert_array = PreprocessFunc::bind_dynamic_insert_2_sec($dynamic_insert,$myTables['CodeSectionArray'],$language);
	}
	//var_dump ($dynamic_insert_array);
}

//////////////////////////////////////////////////////////

if (!GeneralFunc::LogHasErr()){
	//////////////////////////////////////////////////
	//单独测试 目标 某个节表 ---> 
	//$b = 201; //目标节表 编号
	//$a = $myTables['CodeSectionArray'][$b];
	//unset ($myTables['CodeSectionArray']);
	//$myTables['CodeSectionArray'][$b] = $a;
	//////////////////////////////////////////////////
    
	//把需要处理的代码段提取出来，放到一个文件内，并对其进行反汇编
	$bin_filesize = 0;

	if (!count($myTables['CodeSectionArray'])){ //无目标
	    GeneralFunc::LogInsert($language['no_target_sec']);
	}else{		
		$p_sec_abs = array(); //保护区域(绝对 [开始] => 结束)(反汇编代码行号)
		$asm_size = ReadyFunc::collect_and_disasm($bin_file,$asm_file,$disasm,$myTables['CodeSectionArray'],$buf,$bin_filesize,$protect_section_array,$p_sec_abs,$language,false);            

		if (!GeneralFunc::LogHasErr()){
			$exetime_record['collect_and_disasm'] = GeneralFunc::exetime_record(); //获取程序执行的时间
		   
			$LineNum_Code2Reloc = array();  //代码对应重定位
											//$LineNum_Code2Reloc[节表编号][代码行数][重定位编号 1] = true;
											//                                       [.........  2] = true;
											//
			$AsmLastSec = array();          //节表末尾标行号[节表编号][代码行数] = true;
											//
			if ($asm_size){
				if (ReadyFunc::format_disasm_file($asm_file,$bin_filesize,$AsmResultArray,$language)){
					$exetime_record['format_disasm_file'] = GeneralFunc::exetime_record(); //获取程序执行的时间
                    if (!empty($protect_section)){ //处理 保护段 (把汇编指令修正为: db xx ，并合并为一个单位)  
					    PreprocessFunc::format_protect_section ($p_sec_abs,$AsmResultArray,$language);
						$exetime_record['format_protect_section'] = GeneralFunc::exetime_record(); //获取程序执行的时间						
					}
					ReadyFunc::sec_reloc_format($myTables,$AsmResultArray,$AsmLastSec,$language,$LineNum_Code2Reloc);			
					$exetime_record['sec_reloc_format'] = GeneralFunc::exetime_record(); //获取程序执行的时间
				}
			}else{
				GeneralFunc::LogInsert($language['disasm_file_not_found']);
			}
		}
	}
}

if (!GeneralFunc::LogHasErr()){
	//
	//为所有eip跳转指令(重定位的都设跳转目标为下一指令) 定位 以后添加 Label 的位置信息
	//替换  eip跳转指令 后的常数为 Label
	$solid_jmp_array = array();    //保存固定跳转 Dest / Source 的数组 $solid_jmp_array[sec][dest][n] = Label Name
	$solid_jmp_to    = array();    //保存固定跳转 来源 -> 目的         $solid_jmp_to   [sec][source]  = dest

	ReadyFunc::eip_label_replacer($AsmLastSec,$solid_jmp_array,$solid_jmp_to,$myTables,$AsmResultArray,$LineNum_Code2Reloc,$language);

	//对重定位 目标 进行标号/define 变量名替换
	ReadyFunc::rel_label_replacer($myTables,$AsmResultArray,$LineNum_Code2Reloc,$language);

	$exetime_record['eip rel label replace'] = GeneralFunc::exetime_record(); //获取程序执行的时间
   
	$garble_rel_info = array();  //保存混淆后的重定位信息
								 //结构 struct [段编号][原始编号][副本号] => [SymbolTableIndex] 符号表 索引号
								 //                                          [Type]             类型 rel32 dir32
								 //                                          [value]            原始值
								 //                                          [isLabel]          值 or 地址Label
								 //                                          [isMem]            普通值 or 内存地址
								 //注：原始的副本号为 0
								 //
    // 过滤 重定位 type = 20 必须为跳转标号 / type = 6 必须是值(非标号) | 否则丢弃此段，不做处理
	$z = $myTables['CodeSectionArray'];
	foreach ($z as $a => $b){
		if (isset($myTables['RelocArray'][$a])){
			foreach ($myTables['RelocArray'][$a] as $c => $d){
				if (((20 === $d['Type']) and (isset($d['isLabel'])) and (true === $d['isLabel'])) or (( 6 === $d['Type']) and ((!isset($d['isLabel'])) or (!$d['isLabel'])))){
				    $garble_rel_info[$a][$c][0] = $d;
				}else{
					unset ($myTables['CodeSectionArray'][$a]);						
                    GeneralFunc::LogInsert($language['section_name']." ".$b['name'].$language['section_number']." $a ".$language['illegal_rel_type'],2);
                    break;  
				}
			}
		}
	}

	//
	$StandardAsmResultArray = array();	//保存 标准化后 的代码  
									 	//[line_number] => array(
                                        // 'PREFIX'[] => 前缀
                                        // 'OPERATION'=> 指令
                                        // 'PARAMS'[] => array ( 参数
                                        //                   '0' => 'eax';
                                        //                   '1' => '1'
                                        //                   '2' => '[eax+0x0]'
                                        //               )
                                        // 'P_TYPE'[] => array ( 参数类型
                                        //                   '0' => 'r','1' => 'i','2' => 'm'
                                        //               )
                                        // 'P_BITS'[] => array ( 参数位数
                                        //                   '0' => 32, '1' => 0 //整数无位数, '2' => 32
                                        //               )


	
	$normal_register_opt_array = array(); //普通寄存器操作记录 数组
										  //[sec][line][reg][bits] = 1 readonly 2 writeonly 3 read & write
	$flag_register_opt_array   = array(); //标志寄存器操作记录 数组
										  //[sec][line][reg] = 1 readonly 2 writeonly 3 read & write
	$valid_mem_opt_array       = array(); //有效内存  操作记录 数组
										  //[sec][line][][code]  = '[eax+012]'
										  //             [reg][] = 'EAX' 
										  //             [bits]  = 32
										  //             [opt]   = 1 readonly 2 writeonly 3 read & write
										  //             [reloc] = '7_3_0'
										  //
										  //                                                 _
    $stack_used                = array(); // //堆栈  使用       (非参数，被操作)              | [sec][line] => true;
	$stack_broke               = array(); // //堆栈  ESP 被改变 (ESP作为参数 且 Opt > 1)     _|

	ReadyFunc::standard_asm($myTables,$garble_rel_info,$AsmResultArray,$StandardAsmResultArray,$stack_used,$stack_broke,$language);

	$exetime_record['disasm to standard'] = GeneralFunc::exetime_record(); //获取程序执行的时间

	// 逐个分析 节表中代码 所有可能 流程 [section][thread No][n]   => line_number
	$exec_thread_list = array();

	ReadyFunc::exec_thread_list_get($myTables['CodeSectionArray'],$StandardAsmResultArray,$exec_thread_list,$solid_jmp_to,$AsmLastSec);

	$exetime_record['exec thread list'] = GeneralFunc::exetime_record(); //获取程序执行的时间

	//显式/隐式 可用记录 见 readme.txt 2013/04/15
	$soul_forbid = array(); 
	$soul_usable = array();             //$soul_usable[section][line][prev][reg_write_able ] [EAX] => bits
										//                                 [flag_write_able] [CF]  => 1
										//                                 [mem_read_able  ] ?
										//                                 [mem_write_able ] ?
										//                                  
										//                           [next]
										//

	//获得 灵魂(代码)前后 可用(读写) 通用/标志 寄存器 及 内存地址 , 堆栈可用 一览 
	ReadyFunc::get_soul_usable_limit($myTables['CodeSectionArray'],$exec_thread_list,$StandardAsmResultArray,$stack_used,$stack_broke);
	
	$exetime_record['usable register and memory'] = GeneralFunc::exetime_record(); //获取程序执行的时间

	//压缩相同 可用内存 描述，以减少 生成配置文件的体积 readme 2013/04/02
	//$all_valid_mem_opt_record  = array(); //有效内存  集 [CODE][BITS][opt] = index number | [函数 中 局部变量]
	$all_valid_mem_opt_index   = array();   //有效内存索引 [index number] = CODE => '[...]'
											//                              BITS => 8/16/32
											//                              OPT  => 1/2/3
	$soul_usable = ReadyFunc::compress_same_char_output($soul_usable,$all_valid_mem_opt_index);        
	
	$exetime_record['compress same char to output'] = GeneralFunc::exetime_record(); //获取程序执行的时间

	//////////////////////////////////////////////////////////////////////////////////////////////////////
	//抽取 节表名 .xxx$bbb >> convert to >> 'bbb'
	//$sec_name[xxx][] = sec_number
	$sec_name = array();
	foreach ($myTables['CodeSectionArray'] as $a => $b){
		$sec_name[$b['name']][] = $a;
	}

    //////////////////////////////////////////////////////////////////////////////////////////////////////
    //对 各节表 指令/Label 生成 代码顺序写入 双向链表
	$soul_writein_Dlinked_List_Total = array();
	$soul_len_array = array(); // record length of all instructions
	foreach ($myTables['CodeSectionArray'] as $sec => $b){            
	    $soul_writein_Dlinked_List = array();
		$s_w_Dlinked_List_index = DEFAULT_DLIST_FIRST_NUM;
		$prev = false;	
		$c_solid_jmp_array = isset($solid_jmp_array[$sec])?$solid_jmp_array[$sec]:NULL;

		$lp_asm_result = count($StandardAsmResultArray[$sec]) + 1;

		$label_index = -1; //label 编号从 -1 起
    	foreach ($StandardAsmResultArray[$sec] as $z => $y){			
		    ReadyFunc::generat_soul_writein_Dlinked_List($soul_writein_Dlinked_List,$soul_len_array,$z,$s_w_Dlinked_List_index,$prev,$c_solid_jmp_array);
		    unset ($c_solid_jmp_array[$z]);
		}
		if (!empty($c_solid_jmp_array)){//剩余的label 都放在末尾 (继承 上一指令的 next_usable)
			foreach ($c_solid_jmp_array as $x => $y){
				if ($x <= $z){ //出错了，放到末尾的标号不能比最后一个有效指令行数小，这里直接返回致命错误，不再做放弃当前
							   //-节表处理，因为前面 已做过滤处理，这里出错说明代码逻辑有问题，需要修正
					GeneralFunc::LogInsert($language['section_name']." ".$b['name'].$language['section_number']." $sec ".$language['jmp_dest_out_rang_error']);
					break;
				}
				foreach ($y as $w){
					ReadyFunc::add_soul_writein_Dlinked_List($soul_writein_Dlinked_List,$soul_len_array,$s_w_Dlinked_List_index,$prev,$w,$z,true);
				}
			}		
		}
		$soul_writein_Dlinked_List_Total[$sec] = $soul_writein_Dlinked_List;
	}
}    

if (!GeneralFunc::LogHasErr()){
// 所有元素key与Dlinked_List' key 统一
// var_dump ($valid_mem_opt_array);
	if (!ReadyFunc::unified_by_DList_key()){
		GeneralFunc::LogInsert('fail to call ReadyFunc::unified_by_DList_key()',ERROR);
	}
}
// var_dump ($valid_mem_opt_array);
// echo '<br>HIRO:';
// var_dump ($normal_register_opt_array);
// var_dump ($soul_writein_Dlinked_List_Total);
// var_dump ($soul_len_array[6]);
// var_dump ($StandardAsmResultArray[6]);
// var_dump ($soul_forbid[6]); 
// var_dump ($soul_usable[6]); 
// exit;

// 根据 dynamic insert 记录 替换 $StandardAsmResultArray 中对应 整数参数
$dynamic_insert_result = array();
if (!GeneralFunc::LogHasErr()){
	$dynamic_insert_result = PreprocessFunc::dynamic_insert_dealer($dynamic_insert_array,$StandardAsmResultArray);
}

if (!GeneralFunc::LogHasErr()){
	//对可用内存地址(含重定位部分) 进行解析,见 readme.arrays.txt 
	ReadyFunc::parser_rel_usable_mem ($all_valid_mem_opt_index);

    //扫描所有关联的可用通用寄存器 与 内存 指针 ,如:usable.reg: edi & usable.mem: [edi] 
    //$AffiliateUsableArray = scan_affiliate_usable ($soul_usable,$soul_forbid);
	ReadyFunc::scan_affiliate_usable ($soul_usable,$soul_forbid);

	//所有堆栈有效单位，禁止对堆栈指针的可写定义
	foreach ($sec_name as $a => $b){	    
	    foreach ($b as $c => $sec_id){
	    	$f = DEFAULT_DLIST_FIRST_NUM;
			$c_list = $soul_writein_Dlinked_List_Total[$sec_id][$f];
			while (true){                
				if (isset($soul_usable[$sec_id][$f][P][STACK])){ //堆栈有效
				    unset($soul_usable[$sec_id][$f][P][NORMAL_WRITE_ABLE][STACK_POINTER_REG]);
                    $soul_forbid[$sec_id][$f][P][NORMAL][STACK_POINTER_REG][32] = true;
				}
				if (isset($soul_usable[$sec_id][$f][N][STACK])){ //堆栈有效
				    unset($soul_usable[$sec_id][$f][N][NORMAL_WRITE_ABLE][STACK_POINTER_REG]);
                    $soul_forbid[$sec_id][$f][N][NORMAL][STACK_POINTER_REG][32] = true;
				}
				if (false !== $c_list[N]){
					$f = $c_list[N];
					$c_list = $soul_writein_Dlinked_List_Total[$sec_id][$f];
				}else{
					break;
				}
			}
		}    
	}		


    $all_valid_mem_opcode_len = array();
	//////////////////////////////////////////////////////////////////////////////////////////////////////
	//取得所有单位 mem 参数 对opcode 长度的影响		
	require dirname(__FILE__)."/library/oplen.func.php";
	
	foreach ($soul_writein_Dlinked_List_Total as $sec => $z){			
		foreach ($z as $a => $b){
			if (isset($StandardAsmResultArray[$sec][$a][LABEL])){
			
			}else{
				if (isset($StandardAsmResultArray[$sec][$a][P_TYPE])){
					foreach ($StandardAsmResultArray[$sec][$a][P_TYPE] as $c => $d){
						if ('m' === $d){
							$c_len = OpLen::code_len($StandardAsmResultArray[$sec][$a],true);
							if ($c_len <= $soul_len_array[$sec][$a]){
								$all_valid_mem_opcode_len[$StandardAsmResultArray[$sec][$a][PARAMS][$c]] = $soul_len_array[$sec][$a] - $c_len;
							}
						}
					}
				}
			}			
		}
		echo "<br>##########################  $sec ######################";
		echo '<br>$all_valid_mem_opcode_len:';
		var_dump ($all_valid_mem_opcode_len);
		//exit;
	}
		 
	$exetime_record['init mem_addition'] = GeneralFunc::exetime_record(); //获取程序执行的时间   
}

if (!GeneralFunc::LogHasErr()){
	// 对隔断代码(如 call ,ret 等)的后方保护，再处理 根据:(如果后面还有单位，则复制后单位的前保护；如果后面没有单位，则去掉所有soul_usable ,soul_forbid)
	ReadyFunc::redeal_split_opt($StandardAsmResultArray,$exec_thread_list,$soul_forbid,$soul_usable);	
	// 根据执行流程 获取 堆栈平衡块
	foreach ($exec_thread_list as $sec => $exec_thread){
		$c_solid_jmp_array = NULL;
		if (isset($solid_jmp_to[$sec])){
			$c_solid_jmp_array = $solid_jmp_to[$sec];
		}
		$stack_balance_array[$sec] = StackBalance::start ($c_solid_jmp_array,$StandardAsmResultArray[$sec],$exec_thread,$soul_writein_Dlinked_List_Total[$sec]);
	}
}

if (!GeneralFunc::LogHasErr()){
	ValidMemAddr::init($all_valid_mem_opt_index,count($all_valid_mem_opt_index));
	$organs = array();
	// init whole units array
	foreach ($soul_writein_Dlinked_List_Total as $sec => $c_dlist){		
		OrgansOperator::init($sec,$c_dlist,$StandardAsmResultArray[$sec],$soul_usable[$sec],$soul_forbid[$sec],$soul_len_array[$sec],$normal_register_opt_array[$sec]);
		if (!OrgansOperator::initRelJmp()){
			GeneralFunc::LogInsert("initRelJmp() return fail! sec: $sec",ERROR);
		}		
		$organs[$sec] = OrgansOperator::export();
		if (defined('DEBUG_ECHO')){			
			DebugShowFunc::my_shower_01($sec,$exec_thread_list[$sec]);			
			OrgansOperator::show();
		}
	}
}
	
	//////////////////////////////////////////////////////////////////////////////////////////////////////
	//测试 opcode len 计算
	// if (false === true){
	//     require dirname(__FILE__)."/library/oplen.func.php";
        
	// 	foreach ($soul_writein_Dlinked_List_Total as $sec => $z){
 //            echo "<br>##########################  $sec ######################";  			
	// 		foreach ($z as $a => $b){
	// 			if (isset($StandardAsmResultArray[$sec][$a][LABEL])){
				
				
	// 			}else{
	// 				$c_len = OpLen::code_len($StandardAsmResultArray[$sec][$a]);
	// 				//if ($b['len'] !== $c_len){
	// 					echo "<br>";
	// 					var_dump($StandardAsmResultArray[$sec][$a]);
	// 					echo "<br>len = ".$b['len'];
	// 					echo " = $c_len";
	// 				//}
	// 			}
	// 		}
	// 	}
	// 	exit;
	// }

if (!GeneralFunc::LogHasErr()){
	// unset all empty unit
	// $soul_forbid = GeneralFunc::multi_array_filter($soul_forbid);
	//////////////////////////////////////////////////////////////////////////////////////////////////////
	//初始化完成，将数据保存入文档，供给下一步骤使用 
	$rdy_output['garble_rel_info']                 = $garble_rel_info;
  
    $rdy_output['UniqueHead']                      = UNIQUEHEAD;
	$rdy_output['CodeSectionArray']                = $myTables['CodeSectionArray'];

	$rdy_output['preprocess_sec_name']             = $preprocess_sec_name;
	
	$rdy_output['valid_mem_index']                 = $all_valid_mem_opt_index;
	$rdy_output['valid_mem_len']                   = $all_valid_mem_opcode_len;
	$rdy_output['valid_mem_index_ptr']             = count($all_valid_mem_opt_index);
	$rdy_output['sec_name']                        = $sec_name;
	
	$rdy_output['output_type']                     = CfgParser::params('type'); //binary or coff 
	$rdy_output['engin_version']                   = ENGIN_VER;
	$rdy_output['preprocess_config']               = CfgParser::get_preprocess_config();
	$rdy_output['dynamic_insert']                  = $dynamic_insert_result;

	$rdy_output['organs'] = $organs;

	$rdy_output['stack_balance_array'] = $stack_balance_array;

	file_put_contents($rdy_file,serialize($rdy_output)); 			
}
echo "<br><br><br><br>";
echo "binary size: ";
if (isset($asm_size)){
	var_dump ($asm_size);
}
echo "<br><br><br><br>";

$complete_finished = true;
exit;

?>