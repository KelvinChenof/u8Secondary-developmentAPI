using System;
using System.Runtime.InteropServices;
namespace U8OmApi {
 internal static class ResourceTests {
  static int count;
  static void Check(bool value,string name){if(!value)throw new Exception(name);count++;}
  static void Reject(Action run,string name){try{run();}catch(InvalidOperationException){count++;return;}throw new Exception(name);}
  public static int Main(){
   IntPtr table=Marshal.AllocHGlobal(4+3*24);
   try{
    for(int i=0;i<4+3*24;i++)Marshal.WriteByte(table,i,0);
    Marshal.WriteInt32(table,3);
    Marshal.WriteInt32(table,4+20,123);Marshal.WriteInt32(table,4+24+20,321);Marshal.WriteInt32(table,4+48+20,123);
    Check(TcpOwners.Parse(table,76,123,false)==2,"counts only requested owner");
    Reject(delegate{TcpOwners.Parse(table,50,123,false);},"reject truncated native table");
    Marshal.WriteInt32(table,-1);Reject(delegate{TcpOwners.Parse(table,76,123,false);},"reject corrupt count");
   }finally{Marshal.FreeHGlobal(table);}
   table=Marshal.AllocHGlobal(60);
   try{for(int i=0;i<60;i++)Marshal.WriteByte(table,i,0);Marshal.WriteInt32(table,1);Marshal.WriteInt32(table,56,123);Check(TcpOwners.Parse(table,60,123,true)==1,"IPv6 owner row layout");}finally{Marshal.FreeHGlobal(table);}
   Check(TcpOwners.Count(System.Diagnostics.Process.GetCurrentProcess().Id)>=0,"native Windows TCP enumeration runs");
   ResourceBudget.Check(10,1000,100000000);
   Reject(delegate{ResourceBudget.Check(128,1000,100000000);},"TCP hard limit");
   Reject(delegate{ResourceBudget.Check(10,10000,100000000);},"handle hard limit");
   Reject(delegate{ResourceBudget.Check(10,1000,768L*1024*1024);},"private memory hard limit");
   Console.WriteLine("RESOURCE_TESTS_OK="+count);return 0;
  }
 }
}
