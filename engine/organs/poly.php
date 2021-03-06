<?php


if(!defined('UNEST.ORG')) {
        exit('Access Denied');
}

class OrganPoly{

    private static $_index = 1; //poly 唯一编号，一组一个

	private static $_poly_model_index;
	private static $_poly_model_repo;

    // init
	public static function init(){
		$cf = @file_get_contents(dirname(__FILE__)."/../templates/poly.tpl");
        if ($cf == false){
			GeneralFunc::LogInsert('fail to open poly templates file: '.dirname(__FILE__)."/../templates/poly.tpl",WARNING);
		}else{
			$tmp = unserialize($cf);//反序列化，并赋值  
			if (POLY_TPL_VER !== $tmp['version']){
			    GeneralFunc::LogInsert('unmatch poly template version: ('.POLY_TPL_VER.' !== '.$tmp['version'].') '.dirname(__FILE__)."/../templates/poly.tpl",WARNING);
			}else{
			    self::$_poly_model_index = $tmp['index'];
				self::$_poly_model_repo  = $tmp['repo'];
			}
		}
	}
	// 比较 2个内存地址是否相同，考虑重定位 副本的问题
	// DWORD [DWORD FS:UNEST_RELINFO_104_71_2] === DWORD [DWORD FS:UNEST_RELINFO_104_71_0]
	private static function is_same_mem($a,$b){
						
		if ($a === $b){
			return true;
		}
		//考虑重定位
		$replacement = UNIQUEHEAD.'RELINFO_'.'$2_$3';
		$pattern_reloc_4_replace = '/('.UNIQUEHEAD.'RELINFO_(\d+)_(\d+)_(\d+))/';  //匹配 reloc 信息
		
		$a = preg_replace($pattern_reloc_4_replace,$replacement,$a); 
		$b = preg_replace($pattern_reloc_4_replace,$replacement,$b); 

		if ($a === $b){
			return true;
		}
		return false;
	}
	// 继承前后可用记录给多态代码
	private static function inherit_usable_to_poly(&$poly_model,$specific_usable,$soul_usable,$flag_forbid,$param_forbid,$rand_forbid,$rand_result,$org){

		foreach ($poly_model[CODE] as $a => $b){ //应根据流程来走，这里简单起见，以代码顺序来走 | 直到有多态模板复杂到需要按流程来进行
			if (isset($soul_usable[P])){
				$poly_model[USABLE][$a][P] = $soul_usable[P];     
				$poly_model[USABLE][$a][N] = $soul_usable[P];     
			}
		}
		
		if (isset($soul_usable[N])){
			$poly_model[USABLE][$a][N] = $soul_usable[N];     
		}else{
			unset ($poly_model[USABLE][$a][N]);     
		}

		//特指 的 usable继承
		if ($specific_usable){
			foreach ($specific_usable as $a => $b){			
				if ((isset($b['1']))and($b['1'])){
					unset ($poly_model[USABLE][$a][P]);
					$poly_model[USABLE][$a][P] = $soul_usable[$b['1']];     
				}elseif ($b['2']){
					unset ($poly_model[USABLE][$a][N]);
					$poly_model[USABLE][$a][N] = $soul_usable[$b['2']];     
				}
			}
		}

		//继承中 去除 模板中进制  禁止操作 项 (flag reg)
		if (isset ($flag_forbid)){
			if (isset ($flag_forbid[P])){
				foreach ($flag_forbid[P] as $z => $y){
					foreach ($y as $v => $w){
						if (isset($poly_model[USABLE][$z][P][FLAG_WRITE_ABLE][$v])){
							unset ($poly_model[USABLE][$z][P][FLAG_WRITE_ABLE][$v]);
						}
					}
				}
			}
			if (isset ($flag_forbid[N])){
				foreach ($flag_forbid[N] as $z => $y){
					foreach ($y as $v => $w){
						if (isset($poly_model[USABLE][$z][N][FLAG_WRITE_ABLE][$v])){
							unset ($poly_model[USABLE][$z][N][FLAG_WRITE_ABLE][$v]);
						}
					}
				}
			}
			unset ($poly_model[FLAG_FORBID]);
		}


		//继承中 去除 模板中进制  禁止操作 项 (参数)
		if (isset ($param_forbid)){
			if (isset ($param_forbid[P])){
				foreach ($param_forbid[P] as $z => $y){
					foreach ($y as $v => $w){
						if ('r' == $org[P_TYPE][$v]){ //简化处理，直接去掉目标通用寄存器 所有可能位数的 可写权限
							$standard_reg = Instruction::getGeneralRegIndex($org[PARAMS][$v]);
							unset ($poly_model[USABLE][$z][P][NORMAL_WRITE_ABLE][$standard_reg]);
							//除寄存器外，还需要去除所有与此寄存器相关的内存地址的usable
							if (isset($poly_model[USABLE][$z][P][MEM_OPT_ABLE])){
								$s = $poly_model[USABLE][$z][P][MEM_OPT_ABLE];
								foreach ($s as $u => $t){
									$mRegs = ValidMemAddr::get($t,REG);
									if (isset ($mRegs)){
										foreach ($mRegs as $j => $k){
											if ($standard_reg === $k){
												unset ($poly_model[USABLE][$z][P][MEM_OPT_ABLE][$u]);
												break;
											}
										}
									}
								}              
							}
						}elseif ('m' == $org[P_TYPE][$v]){ //简化处理，直接去掉目标 内存地址 所有可能位数的 可写权限
							if (isset($poly_model[USABLE][$z][P][MEM_OPT_ABLE])){
								$s = $poly_model[USABLE][$z][P][MEM_OPT_ABLE];
								foreach ($s as $u => $t){
									$mem = ValidMemAddr::get($t);
									if (self::is_same_mem($org[PARAMS][$v],$mem[CODE])){
										if ($mem[OPT] > 1){
											$mem[OPT] = 1;											
											$poly_model[USABLE][$z][P][MEM_OPT_ABLE][$u] = ValidMemAddr::append($mem);
										}
									}
								}
							}
						}
					}
				}
			}
			if (isset ($param_forbid[N])){
				foreach ($param_forbid[N] as $z => $y){
					foreach ($y as $v => $w){
						if ('r' == $org[P_TYPE][$v]){ //简化处理，直接去掉目标通用寄存器 所有可能位数的 可写权限
							$standard_reg = Instruction::getGeneralRegIndex($org[PARAMS][$v]);
							unset ($poly_model[USABLE][$z][N][NORMAL_WRITE_ABLE][$standard_reg]);
							//除寄存器外，还需要去除所有与此寄存器相关的内存地址的usable
							if (isset($poly_model[USABLE][$z][N][MEM_OPT_ABLE])){
								$s = $poly_model[USABLE][$z][N][MEM_OPT_ABLE];
								foreach ($s as $u => $t){
									$mRegs = ValidMemAddr::get($t,REG);
									if (isset ($mRegs)){
										foreach ($mRegs as $j => $k){
											if ($standard_reg === $k){
												unset ($poly_model[USABLE][$z][N][MEM_OPT_ABLE][$u]);
												break;
											}
										}
									}
								}       
							}
						}elseif ('m' == $org[P_TYPE][$v]){ //简化处理，直接去掉目标 内存地址 所有可能位数的 可写权限
							if (isset($poly_model[USABLE][$z][N][MEM_OPT_ABLE])){
								$s = $poly_model[USABLE][$z][N][MEM_OPT_ABLE];
								foreach ($s as $u => $t){
									$mem = ValidMemAddr::get($t);
									if (self::is_same_mem($org[PARAMS][$v],$mem[CODE])){
										if ($mem[OPT] > 1){											
											$mem[OPT] = 1;
											$poly_model[USABLE][$z][N][MEM_OPT_ABLE][$u] = ValidMemAddr::append($mem);                                                                 
										}
									}
								}
							}
						}
					}
				}
			}
		}

		//继承中 去除 模板中进制  禁止操作 项 (随机值)
		if (isset ($rand_forbid)){
			if (isset ($rand_forbid[P])){
				foreach ($rand_forbid[P] as $z => $y){
					foreach ($y as $v => $w){
						if (Instruction::getGeneralRegIndex($rand_result[$v])){ //通用寄存器 简化处理 不区分bits 全部取消
							$cri = Instruction::getGeneralRegIndex($rand_result[$v]);
							unset ($poly_model[USABLE][$z][P][NORMAL_WRITE_ABLE][$cri]);
						}else{                                           //内存地址
							if (isset($poly_model[USABLE][$z][P][MEM_OPT_ABLE])){
								$x = $poly_model[USABLE][$z][P][MEM_OPT_ABLE];
								foreach ($x as $t => $u){
									$mCode = ValidMemAddr::get($u,CODE);								
									if (self::is_same_mem($rand_result[$v],$mCode)){
										unset ($poly_model[USABLE][$z][P][MEM_OPT_ABLE][$t]); 
									}
								}
							}
						}
					}
				}
			}
			if (isset ($rand_forbid[N])){
				foreach ($rand_forbid[N] as $z => $y){
					foreach ($y as $v => $w){
						if (Instruction::getGeneralRegIndex($rand_result[$v])){ //通用寄存器 简化处理 不区分bits 全部取消
							$cri = Instruction::getGeneralRegIndex($rand_result[$v]);
							unset ($poly_model[USABLE][$z][N][NORMAL_WRITE_ABLE][$cri]);
						}else{                                           //内存地址
							if (isset($poly_model[USABLE][$z][N][MEM_OPT_ABLE])){
								$x = $poly_model[USABLE][$z][N][MEM_OPT_ABLE];
								foreach ($x as $t => $u){
									$mCode = ValidMemAddr::get($u,CODE);
									if (self::is_same_mem($rand_result[$v],$mCode)){
										unset ($poly_model[USABLE][$z][N][MEM_OPT_ABLE][$t]);                                                                     
									}
								}                                               
							}
						}
					}
				}               
			}
		}
		return;
	}
	// 对可乱序的多态模板进行乱序处理
	// 返回 乱序后的多态模板
	private static function ooo ($poly_model){
		$ret = $poly_model;
			$t = $poly_model[OOO];        
			if (shuffle($t)){
				if ($t != $poly_model[OOO]){
						foreach ($poly_model[OOO] as $a => $b){
								if ($t[$a] != $b){
										$ret[CODE][$t[$a]] = $poly_model[CODE][$b];
										$ret[P_TYPE][$t[$a]]    = $poly_model[P_TYPE][$b];
										$ret[P_BITS][$t[$a]]    = $poly_model[P_BITS][$b];
									}
							}
					}
			}
		return $ret;
	}
	// 当前指令 是否含 随机 内存地址(或用来构成该地址的寄存器) 操作
	// 【用来构成该地址的寄存器】无须判断 见：readme.poly.txt
	// 含有，返回true， 不含，返回false
	private static function org_include_mem($org,$mem){
		if (isset($org[P_TYPE])){
			foreach ($org[P_TYPE] as $a => $b){
				if ($b === 'm'){
					if ($mem[CODE] === $org[PARAMS][$a]){
						return true;
					}
				}
			}
		}
		return false;
	}	
	// 检查目标多态模板是否可用 (new_regs 与 soul_usable['next'] 比较)
	// 随机部分 检查的同时 也 生成
	private static function check_poly_usable ($uid,&$usable_poly_model,&$rand_result){

		$org    = OrgansOperator::getCode($uid);
		$c_usable = OrgansOperator::getUsable($uid);

		$obj = $org[OPERATION];

		$tmp = $usable_poly_model;
		foreach ($tmp as $a => $b){
			// 检查new stack 是否冲突
			if (!OrgansOperator::isUsableStackbyUnit($uid)){
				if ((isset(self::$_poly_model_repo[$obj][$b]['new_stack']))and(true === self::$_poly_model_repo[$obj][$b]['new_stack'])){
					if (defined('DEBUG_ECHO') && defined ('POLY_DEBUG_ECHO')){
						echo "<font color=red>stack conflict!";
						var_dump ($usable_poly_model[$a]);
						echo '</font>';
					}
					unset($usable_poly_model[$a]);
					continue;
				}		    
			}
			////////////////////////
			$break = false;
			if (isset(self::$_poly_model_repo[$obj][$b][NEW_REGS][NORMAL])){ //检查新增 通用 寄存器 或 内存地址
				foreach (self::$_poly_model_repo[$obj][$b][NEW_REGS][NORMAL] as $c => $d){ //目前 仅考虑 32位 通用寄存器
					if (Instruction::getGeneralRegIndex($org[PARAMS][$c])){        //原始指令参数中的通用寄存器
						$c = Instruction::getGeneralRegIndex($org[PARAMS][$c]);
						if ((!isset($c_usable[N][NORMAL_WRITE_ABLE][$c][32]))or(!$c_usable[N][NORMAL_WRITE_ABLE][$c][32])){ //仅 检查 Next 部分，见 readme_poly.txt 2013/04/19
							//echo "<br> $sec $line $c";
							unset ($usable_poly_model[$a]);
							$break = true;
							break;
						}
					}elseif (Instruction::getGeneralRegIndex($c)){                   //独立的通用寄存器
						if ((!isset($c_usable[N][NORMAL_WRITE_ABLE][$c][32]))or(!$c_usable[N][NORMAL_WRITE_ABLE][$c][32])){
							unset ($usable_poly_model[$a]);
							$break = true;
							break;
						}
					}else{ //内存地址
						$available = false;
						foreach ($c_usable[N][MEM_OPT_ABLE] as $e => $f){
							if ((ValidMemAddr::is_writable($f))&&(ValidMemAddr::get($f,CODE) === $org[PARAMS][$c])){
								$available = true;
							}
						}
						if (!$available){
							//var_dump ($org[PARAMS][$c]);
							unset ($usable_poly_model[$a]);
							$break = true;
							break;
						}
					}
				}
			}			
			if ($break){
				continue;
			}
			if ((isset(self::$_poly_model_repo[$obj][$b][NEW_REGS][FLAG]))and(is_array(self::$_poly_model_repo[$obj][$b][NEW_REGS][FLAG]))){ //检查新增 标志 寄存器
				foreach (self::$_poly_model_repo[$obj][$b][NEW_REGS][FLAG] as $c => $d){
					if ((!isset($c_usable[N][FLAG_WRITE_ABLE][$c]))or(!$c_usable[N][FLAG_WRITE_ABLE][$c])){ //仅 检查 Next 部分，见 readme_poly.txt 2013/04/19
						//echo "<br> $sec $line $c";
						unset ($usable_poly_model[$a]);
						$break = true;
						break;
					}
				}
			}                              
			if ($break){
				continue;
			}
			//echo "<br>+++++++++++++++++++++++++++++++++++++++++++++++<br>";
			if (isset(self::$_poly_model_repo[$obj][$b][DRAND])){ //需要获得 随机数(寄存器/内存)
															//
															// 目前为简单起见，仅处理 32 位
															//
				// $c_usable_normal = $c_usable[P][NORMAL_WRITE_ABLE];
				$rand_mem = false;
				foreach (self::$_poly_model_repo[$obj][$b][DRAND] as $z => $y){
					if (shuffle ($y)){
						foreach ($y as $x){
							if ($x == 'i'){
								$r_int = GenerateFunc::rand_interger();							
								$rand_result[$a][$z] = $r_int['value'];
								$rand_result[$a][P_TYPE][$z] = 'i';
								$rand_result[$a][P_BITS][$z] = 32; // 整数一律 默认32位
							}elseif (($x == 'm32')&&(!$rand_mem)){
								$c_usable_mem_readonly = false;
								$c_usable_mem_writable = false;
								if (isset($c_usable[P][MEM_OPT_ABLE])){
									foreach ($c_usable[P][MEM_OPT_ABLE] as $v => $w){
										if (32 == ValidMemAddr::get($w,BITS)){//合适的位数
											$c_usable_mem_readonly[$w] = true;
												if (ValidMemAddr::is_writable($w)){   //前方可写入 内存地址
													$c_usable_mem_writable[$w] = true;										
												}
										}
									}
									if (self::$_poly_model_repo[$obj][$b][RAND_PRIVILEGE][$z] >=2){ //需要写权限
										if (false !== $c_usable_mem_writable){
											$w = GeneralFunc::my_array_rand($c_usable_mem_writable);	
											//当前指令 不含 随机 内存地址(或用来构成该地址的寄存器) 操作
											if (false === self::org_include_mem($org,ValidMemAddr::get($w))){
												$rand_result[$a][$z] = ValidMemAddr::get($w,CODE);
												$rand_result[$a][P_TYPE][$z] = 'm';
												$rand_result[$a][P_BITS][$z] = 32;
												$rand_mem = true; //内存地址只能 一次
											}									
										}
									}elseif (false !== $c_usable_mem_readonly){                                                      //只要读权限
										$w = GeneralFunc::my_array_rand($c_usable_mem_readonly);							
										$rand_result[$a][$z] = ValidMemAddr::get($w,CODE);
										$rand_result[$a][P_TYPE][$z] = 'm';
										$rand_result[$a][P_BITS][$z] = 32;
									}
								}
							}elseif ($x == 'r32'){							
								if ((isset(self::$_poly_model_repo[$obj][$b][RAND_PRIVILEGE][$z]))and(self::$_poly_model_repo[$obj][$b][RAND_PRIVILEGE][$z] >=2 )){ //需要写权限
									if (isset($c_usable[P][NORMAL_WRITE_ABLE])){
										$c_usable_normal_reg = false;
										foreach ($c_usable[P][NORMAL_WRITE_ABLE] as $j => $k){
											if ((isset($k[32]))and($k[32])){
												$c_usable_normal_reg[$j] = true;
											}
										}
										if (false !== $c_usable_normal_reg){
											$rand_result[$a][$z] = array_rand($c_usable_normal_reg);
											$rand_result[$a][P_TYPE][$z] = 'r';
											$rand_result[$a][P_BITS][$z] = 32; // 整数一律 默认32位
										}							
									}
								}else{                                                      //只要读权限
									
									$rand_result[$a][$z] = GeneralFunc::my_array_rand(Instruction::getRegsByBits(32));
									$rand_result[$a][P_TYPE][$z] = 'r';
									$rand_result[$a][P_BITS][$z] = 32; // 整数一律 默认32位
								}   
							}

							if (isset($rand_result[$a][$z])){
								break;
							}
						}
					}
					if (!isset($rand_result[$a][$z])){
						unset ($usable_poly_model[$a]);
						break;
					}
				}
			}
		}
	}
	// 根据 多态模板 生成 多态代码
	// 调用前已做过可用性检查，这里直接生成 返回
	private static function generat_poly_code($org,$soul_usable,$poly_model,$rand_result,$int3 = false){
		global $sec;
		$ret = array();

		if (isset($poly_model[OOO])){ //乱序
			$poly_model = self::ooo($poly_model);
		}
	 
		if ($int3){
			$ret[CODE]['int3'][OPERATION] = 'int3';
		}
		if (isset($poly_model[FAT])){
			$ret[FAT] = $poly_model[FAT];
		}

		$specific_usable = false;
		if (isset($poly_model[SPECIFIC_USABLE])){
			$specific_usable = $poly_model[SPECIFIC_USABLE];
		}

		//修正参数中 数据(固定跳转/原参数继承/...)
		foreach ($poly_model[CODE] as $a => $b){//        foreach ($poly_model[OPERATION] as $a => $b){
			if (isset($b[LABEL])){
				$ret[CODE][$a][LABEL] = UNIQUEHEAD.$b[LABEL].self::$_index;
				continue;
			}	

			$ret[CODE][$a][OPERATION] = $b[OPERATION];
			if (!isset($b[PARAMS])){ //无参数
					continue;
			}
			$bb = $b[PARAMS];
			foreach ($bb as $c => $d){
					if ('SOLID_JMP_' === substr($d,0,10)){ //固定跳转标号
							$tmp = explode ('_',$d);
							$d = UNIQUEHEAD.$d.self::$_index;                                                  
					}else{
						//原参数的继承
						if (preg_match_all('/(p_)([\d]{1,})/',$d,$mat)){
							$mat = array_flip($mat[2]); 
							foreach ($mat as $z => $y){
								if (isset($org[REL][$z])){
									$new = RelocInfo::cloneUnit($org[REL][$z]['i'],$org[REL][$z][C]);
									if (isset($poly_model[REL_RESET][$z])){
										RelocInfo::resetUnit($org[REL][$z]['i'],$new,$poly_model[REL_RESET][$z]);
									}
									$c_org_params = 
									str_replace(UNIQUEHEAD.'RELINFO_'.$sec.'_'.$org[REL][$z]['i'].'_'.$org[REL][$z][C],UNIQUEHEAD.'RELINFO_'.$sec.'_'.$org[REL][$z]['i'].'_'.$new,$org[PARAMS][$z]);
									$ret[CODE][$a][REL][$c]['i'] = $org[REL][$z]['i'];
									$ret[CODE][$a][REL][$c][C]   = $new;	
									$d = str_replace('p_'.$z,$c_org_params,$d);
								}else{  //p_n的n一般不会超过3个(指令参数最多不过3个),所以不考虑p_10会被p_1错误替换的问题
									$d = str_replace('p_'.$z,$org[PARAMS][$z],$d);
								}
								if (!isset($poly_model[P_TYPE][$a][$c])){ //模板中手工指定的优先
									$poly_model[P_TYPE][$a][$c] = $org[P_TYPE][$z];
								}
								if (!isset($poly_model[P_BITS][$a][$c])){ //模板中手工指定的优先
									$poly_model[P_BITS][$a][$c] = $org[P_BITS][$z];
								}
							}
						}
						if (preg_match_all('/(r_)([\d]{1,})/',$d,$mat)){ //多态模板中的rand部分的替换
							$mat = array_flip($mat[2]); 
							foreach ($mat as $z => $y){             
								if ('m' == $rand_result[P_TYPE][$z]){  // 随机 内存 可能含有 重定位数据，
																	   // 重定位数据多态时可能被多处引用，这里需要复制一个新标号
									if ($reloc_info = RelocInfo::transString2Array($rand_result[$z])){
										$new = RelocInfo::cloneUnit($reloc_info['i'],$reloc_info[C]);
										$rand_result[$z] = str_replace(UNIQUEHEAD.'RELINFO_'.$reloc_info['sec'].'_'.$reloc_info['i'].'_'.$reloc_info[C],UNIQUEHEAD.'RELINFO_'.$reloc_info['sec'].'_'.$reloc_info['i'].'_'.$new,$rand_result[$z]);
										$ret[CODE][$a][REL][$c]['i'] = $reloc_info['i'];
										$ret[CODE][$a][REL][$c][C] = $new;
									}
								}
								//p_n的n一般不会超过3个(指令参数最多不过3个),所以不考虑p_10会被p_1错误替换的问题
								$d = str_replace('r_'.$z,$rand_result[$z],$d);
							}
							if (!isset($poly_model[P_TYPE][$a][$c])){ //模板中手工指定的优先
								$poly_model[P_TYPE][$a][$c] = $rand_result[P_TYPE][$z];
							}
							if (!isset($poly_model[P_BITS][$a][$c])){ //模板中手工指定的优先
								$poly_model[P_BITS][$a][$c] = $rand_result[P_BITS][$z];
							}
						}
				}
				$ret[CODE][$a][PARAMS][$c] = $d;
				$ret[CODE][$a][P_TYPE][$c] = $poly_model[P_TYPE][$a][$c];
				$ret[CODE][$a][P_BITS][$c] = $poly_model[P_BITS][$a][$c];					
			}
		}
		//修正 前后文 可用寄存器 及 内存地址
		if (isset($soul_usable)){
			$c_flag_forbid = isset($poly_model[FLAG_FORBID])?$poly_model[FLAG_FORBID]:NULL;
			$c_p_forbid    = isset($poly_model[P_FORBID])?$poly_model[P_FORBID]:NULL;
			$c_r_forbid    = isset($poly_model[R_FORBID])?$poly_model[R_FORBID]:NULL;
			self::inherit_usable_to_poly($ret,$specific_usable,$soul_usable,$c_flag_forbid,$c_p_forbid,$c_r_forbid,$rand_result,$org);
		}
		return $ret;
	}
	// 根据多态目标 返回 可用多态模板数组,无可用返回false 
	// 此处不考虑usable限制，仅根据opt,para 获取所有可用tpl
    public static function get_usable_models($uid){
		$ret = false;
		$obj = OrgansOperator::getCode($uid);
		$reloc_info = OrgansOperator::getRelocInfo($uid);
		if ($obj){
		    $usable_poly_model = NULL;
		    if ((isset($obj[OPERATION]))and(isset(self::$_poly_model_index[$obj[OPERATION]]))){
		    	$usable_poly_model = self::$_poly_model_index[$obj[OPERATION]];
			}
			if (is_array($usable_poly_model)){ // 初步 检测是否有可用多态模板(指令名)
				$p_num = 0;
				if (isset($obj[P_TYPE])){
					$p_num = count($obj[P_TYPE]);				
				}
				if (isset($usable_poly_model[$p_num])){
					$usable_poly_model = $usable_poly_model[$p_num];
				}
				if ($p_num){                    
					foreach ($obj[P_TYPE] as $a => $b){
						if ($b == 'r'){ // 通用寄存器 可能有 直接按寄存器 进行的索引(优先于类型的索引)
							if (isset($usable_poly_model[$obj[PARAMS][$a]])){
								$usable_poly_model = $usable_poly_model[$obj[PARAMS][$a]];	
								continue;
							}
							// r 区分出为堆栈指针的寄存器 's'
							if (OrgansOperator::isSPregParam($uid,$a)){
								$b = 's';
							}							
						}
						if ($b == 'i'){ //常数 有可能含有 重定位 & 常数忽略位数
							if (isset($reloc_info[$a])){
								if ($tmp_rel = RelocInfo::getType($reloc_info[$a]['i'],$reloc_info[$a][C])){
									$tmp_rel = 'rel'.$tmp_rel;
									if (isset($usable_poly_model[$tmp_rel])){
										$usable_poly_model = $usable_poly_model[$tmp_rel];
										continue;
									} 	
								}								
							}							
						}else{
							$b .= $obj[P_BITS][$a]; //加上位数信息
						}
						if (isset($usable_poly_model[$b])){
							$usable_poly_model = $usable_poly_model[$b];
						}else{
							$usable_poly_model = NULL;
							break;
						}
					}
				}
				if (count($usable_poly_model)){											
				    $ret = 	$usable_poly_model;
				}
			}
		}
		return $ret;
	}
	// 对指定指令进行多态处理
	private static function collect_usable_poly_model($obj,&$ret){

		$usable_poly_model = self::get_usable_models($obj);
		
        if ($usable_poly_model){
			$rand_result = array();
			if (is_array($usable_poly_model)){
				self::check_poly_usable($obj,$usable_poly_model,$rand_result);
				//随机获得 多态模板
				if (!empty($usable_poly_model)){
					$x = GeneralFunc::my_array_rand($usable_poly_model);					
					$c_rand_result = isset($rand_result[$x])?$rand_result[$x]:NULL;
					$c_obj    = OrgansOperator::getCode($obj);
					$c_usable = OrgansOperator::getUsable($obj);
					if (isset(self::$_poly_model_repo[$c_obj[OPERATION]][$usable_poly_model[$x]])){ //开始根据 多态模板 生成 多态 代码
						if ('int3' === $x){
							$ret = self::generat_poly_code($c_obj,$c_usable,self::$_poly_model_repo[$c_obj[OPERATION]][$usable_poly_model[$x]],$c_rand_result,true);
						}else{
							$ret = self::generat_poly_code($c_obj,$c_usable,self::$_poly_model_repo[$c_obj[OPERATION]][$usable_poly_model[$x]],$c_rand_result);
						}
						return true;
					}else{						
						global $language;						
						GeneralFunc::LogInsert($language['poly_repo_null'].$c_obj[OPERATION].'['.$x.']',2);
					}
				}
			}
		}
		return false;
	}
	// 多态 处理
	public static function start ($objs){
		$obj = $objs[1];

		$c_poly_result = array();		
		if (self::collect_usable_poly_model($obj,$c_poly_result)){
		// if (self::collect_usable_poly_model($c_obj,$c_usable,$c_poly_result)){
			$insert_List_array = array();
			$c_pos = $obj;
			foreach ($c_poly_result[CODE] as $id => $value){
				$c_usable = $c_fat = false;	
				if (isset($c_poly_result[USABLE][$id])){
					$c_usable = $c_poly_result[USABLE][$id];
				}
				if (isset($c_poly_result[FAT][$id])){
					$c_fat = $c_poly_result[FAT][$id];
				}
				$c_pos = OrgansOperator::newUnitByManual($c_pos,$value,$c_usable,$c_fat);
				OrgansOperator::appendComment($c_pos,'p'.self::$_index,$obj);
				$insert_List_array[] = $c_pos;
			}
			

            // 原单位Character.Rate清零 / 新单位init.character 初始化 & 继承原单位
			$old = Character::getAllRate($obj);
			OrgansOperator::removeDLink($obj);
			foreach ($insert_List_array as $i){
				if ($new = Character::initUnit($i,POLY)){
					Character::mergeRate($i,$new,$old);
				}
			}

			self::$_index ++;

			if (defined('DEBUG_ECHO') && defined ('POLY_DEBUG_ECHO')){
				DebugShowFunc::my_shower_03($obj,$insert_List_array);
			}
		}	
	}
}

?>